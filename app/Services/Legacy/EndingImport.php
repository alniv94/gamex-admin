<?php

namespace App\Services\Legacy;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class EndingImport
{
    /** @var array<string, array<int, int>> */
    private array $logGames = [];

    public const TABLES = [
        'ending_game_statuses', 'ending_game_quarters', 'ending_game_winner_statuses',
        'ending_bet_types', 'ending_slots', 'ending_leagues', 'ending_teams', 'ending_config',
        'ending_traditional_bet_amount_config', 'ending_per_quarter_bet_amount_config',
        'ending_bet_amount_config', 'ending_games', 'ending_bet_amounts',
        'ending_bet_amount_prizes', 'ending_cards', 'ending_bets', 'ending_game_results',
        'ending_game_winners', 'ending_agent_bet_commissions', 'ending_sub_agent_bet_commissions',
        'admin_user_logs',
    ];

    public const ACTIONS = [
        'create_game', 'update_game', 'game_betting', 'create_card', 'add_card', 'subtract_card',
        'create_game_result', 'updated_game_result', 'verify_winner', 'unverify_winner',
        'approve_winner', 'generate_pdf', 'set_is_lucky_four', 'create_league', 'update_league',
        'create_team', 'update_team',
    ];

    public function sourceQuery(string $table): Builder
    {
        if (! in_array($table, self::TABLES, true)) {
            throw new RuntimeException('Unsupported ending source table.');
        }
        $query = DB::connection('legacy')->table($table);
        if ($table === 'ending_config') {
            $query->where(function (Builder $q): void {
                $q->whereIn('config_name', ['Game Betting Disabled', 'Card Allow Open', 'Hours allowed to create additional card'])
                    ->orWhere('config_name', 'like', 'Traditional Card - % Quarter Prize Percent')
                    ->orWhere('config_name', 'like', 'Per Quarter Card - % Quarter Prize Percent');
            });
        }
        if ($table === 'admin_user_logs') {
            $query->whereIn('action', self::ACTIONS)->where(function (Builder $q): void {
                $q->whereNotIn('action', ['verify_winner', 'unverify_winner', 'approve_winner'])->orWhere(function (Builder $q): void {
                    $q->whereNull('details')->orWhere(function (Builder $q): void {
                        $q->where('details', 'not like', '%Lucky Four%')->where('details', 'not like', '%Power Ball%');
                    });
                });
            });
        }

        return $query;
    }

    public function preserveSequences(): void
    {
        $source = DB::connection('legacy');
        $target = DB::connection();
        if ($source->getDriverName() !== 'mysql' || $target->getDriverName() !== 'mysql') {
            return;
        }
        $sequences = $source->table('information_schema.TABLES')->where('TABLE_SCHEMA', $source->getDatabaseName())
            ->whereIn('TABLE_NAME', self::TABLES)->pluck('AUTO_INCREMENT', 'TABLE_NAME');
        foreach (self::TABLES as $table) {
            $next = (int) ($sequences[$table] ?? 0);
            $name = $table === 'admin_user_logs' ? 'admin_ending_legacy_logs' : $table;
            $current = (int) $target->table('information_schema.TABLES')->where('TABLE_SCHEMA', $target->getDatabaseName())
                ->where('TABLE_NAME', $name)->value('AUTO_INCREMENT');
            if ($next > $current) {
                // Both identifiers come only from the fixed table allowlist; the value is an integer.
                $target->statement("ALTER TABLE `{$name}` AUTO_INCREMENT = {$next}");
            }
        }
    }

    /** @return array<string, array{source: int, existing: int, missing: int}> */
    public function scan(bool $execute, bool $verify, int $batch, ?callable $progress = null): array
    {
        $source = DB::connection('legacy');
        if (! DB::getSchemaBuilder()->hasTable('legacy_player_mappings')) {
            throw new RuntimeException('Import player mappings before ending games.');
        }
        $mysql = $source->getDriverName() === 'mysql';
        if ($mysql) {
            $source->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $source->statement('START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY');
        }
        $report = [];
        try {
            foreach (self::TABLES as $table) {
                if ($table === 'admin_user_logs') {
                    $this->loadLogGames();
                }
                $target = $table === 'admin_user_logs' ? 'admin_ending_legacy_logs' : $table;
                $key = $table === 'ending_agent_bet_commissions' ? 'bet_id' : 'id';
                $counts = ['source' => $this->sourceQuery($table)->count(), 'existing' => 0, 'missing' => 0];
                $this->sourceQuery($table)->orderBy($key)->chunkById($batch, function ($records) use ($table, $target, $key, $execute, $verify, &$counts): void {
                    $rows = $records->map(fn ($r) => (array) $r)->all();
                    $mappings = [];
                    if ($table === 'ending_bets') {
                        $mappings = DB::table('legacy_player_mappings')->where('source', config('ending.legacy_source'))
                            ->whereIn('legacy_player_id', array_column($rows, 'player_id'))->get()->keyBy('legacy_player_id')->all();
                    }
                    $existing = DB::table($target)->whereIn($key, array_column($rows, $key))->get()->keyBy($key);
                    $inserts = [];
                    foreach ($rows as $raw) {
                        $mapped = $this->map($table, $raw, $mappings);
                        $checksum = $this->checksum($raw);
                        $old = $existing->get($raw[$key]);
                        if ($old) {
                            if ($old->legacy_checksum !== $checksum || ! $this->matches($mapped, (array) $old)) {
                                throw new RuntimeException("Ending migration conflict in {$table}, {$key} {$raw[$key]}. No existing row was overwritten.");
                            }
                            $counts['existing']++;
                        } else {
                            if ($verify) {
                                throw new RuntimeException("Missing ending record in {$table}, {$key} {$raw[$key]}.");
                            }
                            $inserts[] = $mapped + ['legacy_checksum' => $checksum];
                            $counts['missing']++;
                        }
                    }
                    if ($execute && $inserts) {
                        DB::transaction(function () use ($target, $inserts): void {
                            // Keep placeholders below SQLite's and MySQL's prepared statement limits.
                            foreach (array_chunk($inserts, 100) as $part) {
                                DB::table($target)->insert($part);
                            }
                        });
                    }
                }, $key);
                $copied = DB::table($target)->whereNotNull('legacy_checksum')->count();
                $report[$table] = $counts;
                if (($execute || $verify) && $copied !== $counts['source']) {
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

    /** @param array<string, mixed> $raw
     * @param  array<int|string, \stdClass>  $mappings
     * @return array<string, mixed>
     */
    private function map(string $table, array $raw, array $mappings): array
    {
        $mapped = $raw;
        $dates = [];
        foreach ($mapped as $column => $value) {
            if ($value !== null && (str_ends_with($column, '_at') || $column === 'schedule')) {
                if (str_starts_with((string) $value, '0000-')) {
                    $dates[$column] = $value;
                    $mapped[$column] = null;
                } else {
                    $mapped[$column] = (new DateTimeImmutable((string) $value, new DateTimeZone('Asia/Manila')))
                        ->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
                }
            }
        }
        $mapped['legacy_dates'] = $dates ? json_encode($dates, JSON_THROW_ON_ERROR) : null;
        if ($table === 'ending_bets') {
            $legacyId = (int) $raw['player_id'];
            $mapping = $mappings[$legacyId] ?? null;
            if ($legacyId > 0 && ! $mapping) {
                throw new RuntimeException("Missing player mapping for ending bet {$raw['id']}. Import or resolve the source identity first.");
            }
            $mapped['legacy_player_id'] = $legacyId;
            $mapped['legacy_player_mapping_id'] = $mapping?->id;
            $mapped['player_id'] = $mapping?->player_id;
        }
        if ($table === 'admin_user_logs') {
            $mapped['game_id'] = $this->logGame($raw);
        }
        if (in_array($table, ['ending_traditional_bet_amount_config', 'ending_per_quarter_bet_amount_config'], true)) {
            $mapped['amount'] = number_format((float) $raw['amount'], 2, '.', '');
        }

        return $mapped;
    }

    /** @param array<string, mixed> $row */
    private function logGame(array $row): ?int
    {
        $action = $row['action'];
        $id = (int) $row['reference_id'];
        if (in_array($action, ['create_game', 'update_game', 'game_betting', 'generate_pdf', 'set_is_lucky_four'], true)) {
            return $this->logGames['games'][$id] ?? null;
        }
        if (in_array($action, ['create_game_result', 'updated_game_result'], true)) {
            $value = $this->logGames['results'][$id] ?? null;
        } elseif (in_array($action, ['create_card', 'add_card', 'subtract_card'], true)) {
            $value = $this->logGames['amounts'][$id] ?? null;
        } elseif (in_array($action, ['verify_winner', 'unverify_winner', 'approve_winner'], true)) {
            $value = $this->logGames['winners'][$id] ?? null;
        } else {
            $value = null;
        }

        return $value === null ? null : (int) $value;
    }

    private function loadLogGames(): void
    {
        $source = DB::connection('legacy');
        $this->logGames = [
            'games' => $source->table('ending_games')->pluck('id', 'id')->map(fn ($id) => (int) $id)->all(),
            'results' => $source->table('ending_game_results')->pluck('game_id', 'id')->map(fn ($id) => (int) $id)->all(),
            'amounts' => $source->table('ending_bet_amounts')->pluck('game_id', 'id')->map(fn ($id) => (int) $id)->all(),
            'winners' => $source->table('ending_game_winners as w')
                ->join('ending_bets as b', 'w.bet_id', '=', 'b.id')->join('ending_cards as c', 'b.card_id', '=', 'c.id')
                ->join('ending_bet_amounts as a', 'c.bet_amount_id', '=', 'a.id')->pluck('a.game_id', 'w.id')->map(fn ($id) => (int) $id)->all(),
        ];
    }

    /** @param array<string, mixed> $row */
    private function checksum(array $row): string
    {
        ksort($row);

        return hash('sha256', json_encode(array_map(fn ($value) => $value === null ? null : (string) $value, $row), JSON_THROW_ON_ERROR));
    }

    /** @param array<string, mixed> $expected
     * @param  array<string, mixed>  $actual
     */
    private function matches(array $expected, array $actual): bool
    {
        foreach ($expected as $column => $value) {
            if ($column === 'legacy_dates' && $value !== null && isset($actual[$column])) {
                $expectedDates = json_decode($value, true, flags: JSON_THROW_ON_ERROR);
                $actualDates = json_decode($actual[$column], true, flags: JSON_THROW_ON_ERROR);
                ksort($expectedDates);
                ksort($actualDates);
                if ($expectedDates === $actualDates) {
                    continue;
                }
            }
            if (! array_key_exists($column, $actual) || ($value === null) !== ($actual[$column] === null)
                || ($value !== null && (string) $value !== (string) $actual[$column])) {
                // SQLite returns decimals as numbers, MySQL as fixed precision strings.
                if (in_array($column, ['amount', 'amount_added', 'cash_prize', 'agent_percent', 'agent_amount', 'master_agent_percent', 'master_agent_amount', 'total_amount', 'sub_agent_percent'], true)
                    && $value !== null && isset($actual[$column]) && is_numeric($value) && is_numeric($actual[$column])
                    && $this->numeric((string) $value) === $this->numeric((string) $actual[$column])) {
                    continue;
                }

                return false;
            }
        }

        return true;
    }

    private function numeric(string $value): string
    {
        return str_contains($value, '.') ? rtrim(rtrim($value, '0'), '.') : $value;
    }
}
