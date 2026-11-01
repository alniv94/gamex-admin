<?php

namespace App\Services\Legacy;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use stdClass;

class LuckyFourImport
{
    public const TABLES = ['lucky_four_prizes_settings', 'lucky_four_prizes_setting_logs', 'lucky_four_prizes', 'lucky_four_bets', 'lucky_four_winners', 'admin_user_logs'];

    /** @var array<string, array<string, bool>> */
    private array $columns = [];

    /** @return array<string, array{source: int, matching: int, missing: int, upgrading: int}> */
    public function scan(bool $execute, bool $verify, int $batch = 500, ?callable $progress = null): array
    {
        $source = DB::connection('legacy');
        $mysql = $source->getDriverName() === 'mysql';
        if ($mysql) {
            $source->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $source->statement('START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY');
        }
        try {
            if (! DB::table('admin_ending_imports')->where('source', config('ending.legacy_source'))->exists()) {
                throw new RuntimeException('Import and verify Ending history before Lucky Four.');
            }
            foreach (self::TABLES as $table) {
                if (! $source->getSchemaBuilder()->hasTable($table)) {
                    throw new RuntimeException("Missing source table {$table}.");
                }
            }
            $report = [];
            foreach (self::TABLES as $table) {
                $report[$table] = $this->copy($table, $execute, $verify, $batch);
                if ($verify && DB::table($this->target($table))->whereNotNull('legacy_id')->count() !== $report[$table]['source']) {
                    throw new RuntimeException("Imported row count differs from the source for {$table}.");
                }
                if ($progress) {
                    $progress($table, $report[$table]);
                }
            }

            return $report;
        } finally {
            if ($mysql) {
                $source->statement('ROLLBACK');
            }
        }
    }

    private function sourceQuery(string $table): Builder
    {
        $query = DB::connection('legacy')->table($table);
        if ($table === 'admin_user_logs') {
            $query->where(fn (Builder $q) => $q->where('details', 'like', '%Lucky Four%')
                ->orWhereIn('action', ['set_is_lucky_four', 'refresh_jackpot_prize', 'update_prizes_setting']));
        }

        return $query;
    }

    /** @return array{source: int, matching: int, missing: int, upgrading: int} */
    private function copy(string $table, bool $execute, bool $verify, int $batch): array
    {
        $query = $this->sourceQuery($table);
        $target = $this->target($table);
        $counts = ['source' => $query->count(), 'matching' => 0, 'missing' => 0, 'upgrading' => 0];
        $query->chunkById($batch, function ($rows) use ($table, $target, $execute, $verify, &$counts): void {
            $existing = DB::table($target)->whereIn('id', $rows->pluck('id'))->get()->keyBy('id');
            $parents = match ($table) {
                'lucky_four_prizes' => DB::table('ending_games')->whereIn('id', $rows->pluck('game_id'))->get()->keyBy('id'),
                'lucky_four_bets' => DB::table('ending_bets as b')->leftJoin('ending_cards as c', 'c.id', '=', 'b.card_id')
                    ->leftJoin('ending_bet_amounts as a', 'a.id', '=', 'c.bet_amount_id')->whereIn('b.id', $rows->pluck('ending_bet_id'))
                    ->get(['b.id', 'b.legacy_checksum', 'b.card_id', 'b.game_type_id', 'a.game_id'])->keyBy('id'),
                default => collect(),
            };
            $games = $table === 'lucky_four_bets' ? DB::table('ending_games')->whereIn('id', $rows->pluck('game_id'))->whereNotNull('legacy_checksum')->pluck('id')->all() : [];
            $inserts = [];
            foreach ($rows as $row) {
                if (in_array($table, ['lucky_four_prizes', 'lucky_four_bets'], true)) {
                    $parent = $parents->get($table === 'lucky_four_prizes' ? $row->game_id : $row->ending_bet_id);
                    $directTicket = $table === 'lucky_four_bets' && $parent && (int) $parent->game_type_id === 3 && (int) $parent->card_id === 0;
                    if (! $parent || ! $parent->legacy_checksum || ($table === 'lucky_four_bets' &&
                        (! in_array((int) $row->game_id, $games, true) || (! $directTicket && (int) $parent->game_id !== (int) $row->game_id)))) {
                        throw new RuntimeException("Missing or conflicting Ending parent in {$table}, key {$row->id}.");
                    }
                }
                $this->checkSourceRelationships($table, $row);
                $mapped = $this->map($table, (array) $row);
                $old = $existing->get($row->id);
                if ($old && $table === 'lucky_four_bets' && $old->legacy_id === null) {
                    $this->checkPartialBet($old, $mapped);
                    if ($verify) {
                        throw new RuntimeException("Lucky Four bet {$row->id} still contains only report amounts. Run --execute to complete it.");
                    }
                    if ($execute) {
                        DB::table($target)->where('id', $row->id)->update($mapped);
                    }
                    $counts['upgrading']++;
                } elseif ($old) {
                    $this->compare($table, $old, $mapped);
                    $counts['matching']++;
                } else {
                    if ($verify) {
                        throw new RuntimeException("Missing Lucky Four record in {$table}, key {$row->id}.");
                    }
                    if ($table === 'lucky_four_bets' && DB::table('lucky_four_winners as w')->join('lucky_four_bets as b', 'b.ending_bet_id', '=', 'w.ending_bet_id')
                        ->where('b.game_id', $row->game_id)->whereNotNull('w.managed_at')->exists()) {
                        throw new RuntimeException('Additional bets cannot be imported after Lucky Four winner processing.');
                    }
                    $inserts[] = $mapped;
                    $counts['missing']++;
                }
            }
            if ($execute) {
                foreach (array_chunk($inserts, 100) as $part) {
                    DB::table($target)->insert($part);
                }
            }
        });

        return $counts;
    }

    private function checkSourceRelationships(string $table, stdClass $row): void
    {
        $parent = match ($table) {
            'lucky_four_prizes_setting_logs' => ['lucky_four_prizes_settings', 'id', $row->prizes_setting_id],
            'lucky_four_winners' => ['lucky_four_bets', 'ending_bet_id', $row->ending_bet_id],
            default => null,
        };
        if ($parent && ! DB::connection('legacy')->table($parent[0])->where($parent[1], $parent[2])->exists()) {
            throw new RuntimeException("Orphaned source record in {$table}, key {$row->id}.");
        }
    }

    /** @param array<string, mixed> $mapped */
    private function checkPartialBet(stdClass $old, array $mapped): void
    {
        if ($old->legacy_checksum !== $mapped['legacy_checksum'] || (int) $old->ending_bet_id !== $mapped['ending_bet_id']
            || ! $this->same('amount', $old->amount, $mapped['amount'])) {
            throw new RuntimeException("Lucky Four amount conflict for record {$old->id}. No existing record was overwritten.");
        }
        foreach (['game_id', 'combination', 'is_added', 'legacy_record_checksum', 'legacy_payload', 'managed_at'] as $column) {
            if ($old->{$column} !== null) {
                throw new RuntimeException("Unexpected data in partial Lucky Four bet {$old->id}, field {$column}.");
            }
        }
    }

    /** @param array<string, mixed> $mapped */
    private function compare(string $table, stdClass $old, array $mapped): void
    {
        $mutable = match ($table) {
            'lucky_four_bets' => ['is_added'],
            'lucky_four_prizes', 'lucky_four_prizes_settings' => ['jackpot_prize', 'three_digits_prize', 'initial_amount', 'percentage_deduction', 'bet_amount', 'amount_added'],
            'lucky_four_winners' => ['winner_status', 'prize_released', 'prize_amount'],
            default => [],
        };
        $compare = $old->managed_at === null ? $mapped : array_diff_key($mapped, array_flip($mutable));
        foreach ($compare as $column => $value) {
            if (! $this->same($column, $old->{$column}, $value)) {
                throw new RuntimeException("Lucky Four import conflict in {$table}, key {$old->id}, field {$column}. No existing record was overwritten.");
            }
        }
    }

    private function same(string $column, mixed $left, mixed $right): bool
    {
        if (in_array($column, ['legacy_payload', 'legacy_dates'], true)) {
            $a = json_decode($left ?? 'null', true, flags: JSON_THROW_ON_ERROR);
            $b = json_decode($right ?? 'null', true, flags: JSON_THROW_ON_ERROR);
            if (is_array($a) && is_array($b)) {
                ksort($a);
                ksort($b);
            }

            return $a === $b;
        }
        if ($left === null || $right === null) {
            return $left === $right;
        }
        if (in_array($column, ['amount', 'bet_amount', 'jackpot_prize', 'three_digits_prize', 'initial_amount', 'percentage_deduction', 'amount_added', 'prize_amount'], true)) {
            return number_format((float) $left, 2, '.', '') === number_format((float) $right, 2, '.', '');
        }

        return (string) $left === (string) $right;
    }

    /** @param array<string, mixed> $raw
     * @return array<string, mixed>
     */
    private function map(string $table, array $raw): array
    {
        $target = $this->target($table);
        $columns = $this->columns[$target] ??= array_fill_keys(Schema::getColumnListing($target), true);
        $mapped = array_intersect_key($raw, $columns);
        $dates = [];
        foreach ($mapped as $column => $value) {
            if ($value !== null && str_ends_with($column, '_at')) {
                if (str_starts_with((string) $value, '0000-')) {
                    $dates[$column] = $value;
                    $mapped[$column] = null;
                } else {
                    $mapped[$column] = CarbonImmutable::parse((string) $value, 'Asia/Manila')->utc()->format('Y-m-d H:i:s');
                }
            }
        }
        if ($table === 'admin_user_logs') {
            $mapped['user_id'] = DB::table('admin_profiles')->where('legacy_admin_id', $raw['user_id'])->value('user_id');
            $mapped['game_id'] = $this->logGame($raw);
        }
        ksort($raw);
        $payload = json_encode($raw, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $mapped['legacy_id'] = $raw['id'];
        $mapped['legacy_payload'] = $payload;
        if ($table === 'lucky_four_bets') {
            $amount = ['id' => (int) $raw['id'], 'ending_bet_id' => (int) $raw['ending_bet_id'],
                'amount' => $raw['amount'] === null ? null : number_format((float) $raw['amount'], 2, '.', '')];
            $mapped = array_replace($mapped, $amount);
            $mapped['legacy_checksum'] = hash('sha256', json_encode($amount, JSON_THROW_ON_ERROR));
            $mapped['legacy_record_checksum'] = hash('sha256', $payload);
        } else {
            $mapped['legacy_checksum'] = hash('sha256', $payload);
            $mapped['legacy_dates'] = $dates ? json_encode($dates, JSON_THROW_ON_ERROR) : null;
        }

        return $mapped;
    }

    /** @param array<string, mixed> $raw */
    private function logGame(array $raw): ?int
    {
        $source = DB::connection('legacy');
        if ($raw['action'] === 'set_is_lucky_four') {
            return $source->table('ending_games')->where('id', $raw['reference_id'])->value('id');
        }
        if ($raw['action'] === 'refresh_jackpot_prize') {
            return $source->table('lucky_four_prizes')->where('id', $raw['reference_id'])->value('game_id');
        }
        if (in_array($raw['action'], ['verify_winner', 'unverify_winner', 'approve_winner'], true)) {
            return $source->table('lucky_four_winners as w')->join('lucky_four_bets as b', 'b.ending_bet_id', '=', 'w.ending_bet_id')
                ->where('w.id', $raw['reference_id'])->value('b.game_id');
        }

        return null;
    }

    public function preserveSequences(): void
    {
        $source = DB::connection('legacy');
        if ($source->getDriverName() !== 'mysql' || DB::connection()->getDriverName() !== 'mysql') {
            return;
        }
        $sequences = $source->table('information_schema.TABLES')->where('TABLE_SCHEMA', $source->getDatabaseName())
            ->whereIn('TABLE_NAME', self::TABLES)->pluck('AUTO_INCREMENT', 'TABLE_NAME');
        foreach (self::TABLES as $table) {
            $target = $this->target($table);
            $next = max((int) ($sequences[$table] ?? 1), (int) DB::table($target)->max('id') + 1);
            DB::statement("ALTER TABLE {$target} AUTO_INCREMENT = {$next}");
        }
    }

    private function target(string $table): string
    {
        return $table === 'admin_user_logs' ? 'admin_lucky_four_legacy_logs' : $table;
    }
}
