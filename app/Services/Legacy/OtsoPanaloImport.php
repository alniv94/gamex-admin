<?php

namespace App\Services\Legacy;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class OtsoPanaloImport
{
    public const TABLES = ['otso_panalo_statuses', 'otso_panalo_bet_types', 'otso_panalo_winner_types', 'otso_panalo_game_configs', 'otso_panalo_games', 'otso_panalo_bets', 'otso_panalo_game_results', 'otso_panalo_bet_quarter_results', 'otso_panalo_winners', 'admin_user_logs'];

    public const WINNER_TYPES = [1 => 'Jackpot', 2 => 'Second Prize', 3 => 'Third Prize', 4 => 'Fourth Prize'];

    /** @var array<string, array<string, bool>> */
    private array $columns = [];

    /** @return array<string, array{source: int, matching: int, missing: int, unlinked: int}> */
    public function scan(bool $execute, bool $verify, int $batch = 500, ?callable $progress = null): array
    {
        $source = DB::connection('legacy');
        $mysql = $source->getDriverName() === 'mysql';
        if ($mysql) {
            $source->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $source->statement('START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY');
        }
        try {
            $this->preflight();
            $report = [];
            foreach (self::TABLES as $table) {
                $query = $source->table($table);
                if ($table === 'admin_user_logs') {
                    $query->where('action', 'like', '%otso_panalo%');
                }
                $report[$table] = $this->copy($table, $query, $execute, $verify, $batch);
                if ($table === 'otso_panalo_winner_types') {
                    $this->ensureWinnerTypes($execute, $verify);
                }
                if (($execute || $verify) && DB::table($this->target($table))->whereNotNull('legacy_id')->count() !== $report[$table]['source']) {
                    throw new RuntimeException("Imported row count differs from the source for {$table}.");
                }
                if ($progress) {
                    $progress($table, $report[$table]);
                }
            }
            if ($execute) {
                $this->generationMarkers();
                $this->preserveSequences();
            }

            return $report;
        } finally {
            if ($mysql) {
                $source->statement('ROLLBACK');
            }
        }
    }

    /** @return array{source: int, matching: int, missing: int, unlinked: int} */
    public function scanBets(int $legacyGameId, bool $execute): array
    {
        return $this->copy('otso_panalo_bets', DB::connection('legacy')->table('otso_panalo_bets')->where('game_id', $legacyGameId), $execute, false, 500);
    }

    private function preflight(): void
    {
        if (! Schema::hasTable('legacy_player_mappings') || ! DB::table('admin_ending_imports')->exists()) {
            throw new RuntimeException('Import and verify player identities and ending games before Otso Panalo.');
        }
        $source = DB::connection('legacy');
        foreach (self::TABLES as $table) {
            if (! $source->getSchemaBuilder()->hasTable($table)) {
                throw new RuntimeException("Missing source table {$table}.");
            }
        }
        foreach (['otso_panalo_statuses' => [1, 2, 3, 4]] as $table => $ids) {
            if ($source->table($table)->whereIn('id', $ids)->count() !== count($ids)) {
                throw new RuntimeException("Required catalog identifiers are missing in {$table}.");
            }
        }
        foreach ([
            ['otso_panalo_games', 'game_config_id', 'otso_panalo_game_configs'],
            ['otso_panalo_games', 'game_status_id', 'otso_panalo_statuses'],
            ['otso_panalo_bets', 'game_id', 'otso_panalo_games'],
            ['otso_panalo_bets', 'bet_type_id', 'otso_panalo_bet_types'],
            ['otso_panalo_game_results', 'game_id', 'otso_panalo_games'],
            ['otso_panalo_bet_quarter_results', 'game_id', 'otso_panalo_games'],
            ['otso_panalo_bet_quarter_results', 'bet_id', 'otso_panalo_bets'],
            ['otso_panalo_winners', 'bet_id', 'otso_panalo_bets'],
        ] as [$table, $column, $parent]) {
            if ($source->table($table.' as child')->leftJoin($parent.' as parent', 'parent.id', '=', 'child.'.$column)->whereNotNull('child.'.$column)->whereNull('parent.id')->exists()) {
                throw new RuntimeException("Orphaned {$column} in {$table}. Resolve the mapping before importing.");
            }
        }
        if ($source->table('otso_panalo_winners')->whereNotIn('winner_type_id', array_keys(self::WINNER_TYPES))->exists()) {
            throw new RuntimeException('Unknown winner tier in the source history. Review it before importing.');
        }
        foreach ($source->table('otso_panalo_games')->pluck('ending_game_id')->chunk(500) as $ids) {
            if (DB::table('ending_games')->whereIn('id', $ids)->whereNotNull('legacy_checksum')->count() !== $ids->unique()->count()) {
                throw new RuntimeException('An Otso Panalo game references ending history that has not been imported.');
            }
        }
        foreach (['otso_panalo_game_results' => ['game_id'], 'otso_panalo_winners' => ['bet_id'], 'otso_panalo_bet_quarter_results' => ['bet_id', 'quarter_number']] as $table => $keys) {
            if ($source->table($table)->select($keys)->groupBy($keys)->havingRaw('COUNT(*) > 1')->exists()) {
                throw new RuntimeException("Duplicate processing keys in {$table}. Review the source history.");
            }
        }
        if ($source->table('otso_panalo_bet_quarter_results as q')->join('otso_panalo_bets as b', 'b.id', '=', 'q.bet_id')->whereColumn('q.game_id', '<>', 'b.game_id')->exists()) {
            throw new RuntimeException('Quarter results reference bets from another game.');
        }
    }

    /** @return array{source: int, matching: int, missing: int, unlinked: int} */
    private function copy(string $table, Builder $query, bool $execute, bool $verify, int $batch): array
    {
        $target = $this->target($table);
        $counts = ['source' => $query->count(), 'matching' => 0, 'missing' => 0, 'unlinked' => 0];
        $query->chunkById($batch, function ($records) use ($table, $target, $execute, $verify, &$counts): void {
            $existing = DB::table($target)->whereIn('id', $records->pluck('id'))->get()->keyBy('id');
            $mappings = $table === 'otso_panalo_bets'
                ? DB::table('legacy_player_mappings')->where('source', config('otso.legacy_source'))->whereIn('legacy_player_id', $records->pluck('player_id'))->get()->keyBy('legacy_player_id')->all() : [];
            $inserts = [];
            foreach ($records as $record) {
                $mapped = $this->map($table, (array) $record, $mappings);
                if ($table === 'otso_panalo_bets' && $mapped['player_id'] === null) {
                    $counts['unlinked']++;
                }
                $old = $existing->get($record->id);
                if ($old) {
                    if ((int) $old->legacy_id !== (int) $record->id || $old->legacy_checksum !== $mapped['legacy_checksum']
                        || ! $this->jsonMatches($old->legacy_payload, $mapped['legacy_payload'])) {
                        throw new RuntimeException("Otso Panalo import conflict in {$table}, key {$record->id}. No existing record was overwritten.");
                    }
                    $compare = $old->managed_at === null ? $mapped : array_intersect_key($mapped, array_flip(['id', 'legacy_id', 'legacy_checksum', 'legacy_payload']));
                    foreach ($compare as $column => $value) {
                        $actual = $old->{$column};
                        if (in_array($column, ['legacy_payload', 'legacy_dates'], true)) {
                            $same = $this->jsonMatches($value, $actual);
                        } elseif (in_array($column, ['bet_amount', 'jackpot_prize', 'second_prize', 'third_prize', 'fourth_prize', 'pool_percentage', 'amount', 'prize_amount'], true) && $value !== null && $actual !== null) {
                            $same = number_format((float) $value, 2, '.', '') === number_format((float) $actual, 2, '.', '');
                        } else {
                            $same = ($value === null) === ($actual === null) && ($value === null || (string) $value === (string) $actual);
                        }
                        if (! $same) {
                            throw new RuntimeException("Otso Panalo verification mismatch in {$table}, key {$record->id}, field {$column}.");
                        }
                    }
                    $counts['matching']++;
                } else {
                    if ($verify) {
                        throw new RuntimeException("Missing Otso Panalo record in {$table}, key {$record->id}.");
                    }
                    if ($table === 'otso_panalo_bets' && $execute) {
                        $game = DB::table('otso_panalo_games')->where('id', $mapped['game_id'])->first();
                        if ($game && ($game->winners_generated_at !== null
                            || DB::table('admin_otso_panalo_audits')->where('game_id', $game->id)->whereIn('action', ['create_result', 'approve_result', 'generate_winners', 'cancel_game'])->exists())) {
                            throw new RuntimeException('Additional legacy bets cannot be imported after result processing or cancellation.');
                        }
                    }
                    $inserts[] = $mapped;
                    $counts['missing']++;
                }
            }
            if ($execute && $inserts) {
                DB::transaction(function () use ($target, $inserts): void {
                    foreach (array_chunk($inserts, 100) as $part) {
                        DB::table($target)->insert($part);
                    }
                });
            }
        });

        return $counts;
    }

    /** @param array<string, mixed> $raw
     * @param  array<int|string, \stdClass>  $mappings
     * @return array<string, mixed>
     */
    private function map(string $table, array $raw, array $mappings): array
    {
        $target = $this->target($table);
        $columns = $this->columns[$target] ??= array_fill_keys(Schema::getColumnListing($target), true);
        $mapped = array_intersect_key($raw, $columns);
        $dates = [];
        foreach ($mapped as $column => $value) {
            if ($value !== null && (str_ends_with($column, '_at') || $column === 'betting_deadline')) {
                if (str_starts_with((string) $value, '0000-')) {
                    $dates[$column] = $value;
                    $mapped[$column] = null;
                } else {
                    $mapped[$column] = CarbonImmutable::parse((string) $value, 'Asia/Manila')->utc()->format('Y-m-d H:i:s');
                }
            }
        }
        if ($table === 'otso_panalo_bets') {
            $mapping = $mappings[$raw['player_id']] ?? null;
            if (! $mapping) {
                throw new RuntimeException("Missing player mapping for Otso Panalo bet {$raw['id']}.");
            }
            $mapped['legacy_player_id'] = $raw['player_id'];
            $mapped['legacy_player_mapping_id'] = $mapping->id;
            $mapped['player_id'] = $mapping->quarantined ? null : $mapping->player_id;
            if ($mapped['player_id'] !== null && ! DB::table('players')->where('id', $mapped['player_id'])->exists()) {
                throw new RuntimeException("The mapped player for bet {$raw['id']} does not exist.");
            }
        }
        if ($table === 'otso_panalo_game_configs' && ! empty($mapped['logo'])) {
            $mapped['logo'] = '/uploads/otso_panalo/game_configs/'.basename(str_replace('\\', '/', parse_url($mapped['logo'], PHP_URL_PATH) ?: $mapped['logo']));
        }
        foreach (['enabled_betting_by', 'disabled_betting_by', 'cancelled_by'] as $column) {
            if (isset($mapped[$column])) {
                $mapped[$column] = DB::table('admin_profiles')->where('legacy_admin_id', $mapped[$column])->value('user_id');
            }
        }
        if ($table === 'admin_user_logs') {
            $mapped['user_id'] = DB::table('admin_profiles')->where('legacy_admin_id', $raw['user_id'])->value('user_id');
            $mapped['game_id'] = $this->logGame($raw);
        }
        ksort($raw);
        $payload = json_encode($raw, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

        return $mapped + ['legacy_id' => $raw['id'], 'legacy_checksum' => hash('sha256', $payload), 'legacy_payload' => $payload, 'legacy_dates' => $dates ? json_encode($dates, JSON_THROW_ON_ERROR) : null];
    }

    /** @param array<string, mixed> $raw */
    private function logGame(array $raw): ?int
    {
        $action = (string) $raw['action'];
        if (str_contains($action, 'game_config')) {
            return null;
        }
        if (str_contains($action, '_result')) {
            return DB::connection('legacy')->table('otso_panalo_game_results')->where('id', $raw['reference_id'])->value('game_id');
        }

        return DB::connection('legacy')->table('otso_panalo_games')->where('id', $raw['reference_id'])->value('id');
    }

    private function target(string $table): string
    {
        return $table === 'admin_user_logs' ? 'admin_otso_panalo_legacy_logs' : $table;
    }

    private function ensureWinnerTypes(bool $execute, bool $verify): void
    {
        foreach (self::WINNER_TYPES as $id => $label) {
            $row = DB::table('otso_panalo_winner_types')->where('id', $id)->first();
            if ($row) {
                if ($row->winner_type !== $label) {
                    throw new RuntimeException('The winner tier catalog conflicts with the legacy OtsoPanaloWinnerTypesSeeder definitions.');
                }
            } elseif ($execute) {
                DB::table('otso_panalo_winner_types')->insert(['id' => $id, 'winner_type' => $label, 'status' => 'Active']);
            } elseif ($verify) {
                throw new RuntimeException('A required winner tier is missing from the target catalog.');
            }
        }
    }

    private function jsonMatches(?string $left, ?string $right): bool
    {
        $a = json_decode($left ?? 'null', true, flags: JSON_THROW_ON_ERROR);
        $b = json_decode($right ?? 'null', true, flags: JSON_THROW_ON_ERROR);
        if (is_array($a) && is_array($b)) {
            ksort($a);
            ksort($b);
        }

        return $a === $b;
    }

    private function generationMarkers(): void
    {
        foreach (DB::table('otso_panalo_winners as w')->join('otso_panalo_bets as b', 'b.id', '=', 'w.bet_id')->select('b.game_id')->selectRaw('MAX(w.created_at) AS generated_at')->groupBy('b.game_id')->get() as $game) {
            DB::table('otso_panalo_games')->where('id', $game->game_id)->whereNull('winners_generated_at')->update(['winners_generated_at' => $game->generated_at ?? now()]);
        }
        foreach (DB::table('otso_panalo_bet_quarter_results')->select('game_id')->selectRaw('MAX(created_at) AS generated_at')->groupBy('game_id')->get() as $game) {
            DB::table('otso_panalo_games')->where('id', $game->game_id)->whereNull('winners_generated_at')->update(['winners_generated_at' => $game->generated_at ?? now()]);
        }
        foreach (DB::table('admin_otso_panalo_legacy_logs')->where('action', 'generate_otso_panalo_winners')->whereNotNull('game_id')->get() as $log) {
            DB::table('otso_panalo_games')->where('id', $log->game_id)->whereNull('winners_generated_at')->update(['winners_generated_at' => $log->created_at ?? now()]);
        }
    }

    private function preserveSequences(): void
    {
        $source = DB::connection('legacy');
        if ($source->getDriverName() !== 'mysql' || DB::connection()->getDriverName() !== 'mysql') {
            return;
        }
        $sequences = $source->table('information_schema.TABLES')->where('TABLE_SCHEMA', $source->getDatabaseName())->whereIn('TABLE_NAME', self::TABLES)->pluck('AUTO_INCREMENT', 'TABLE_NAME');
        foreach (self::TABLES as $table) {
            $target = $this->target($table);
            $next = max((int) ($sequences[$table] ?? 1), (int) DB::table($target)->max('id') + 1);
            DB::statement("ALTER TABLE {$target} AUTO_INCREMENT = {$next}");
        }
    }
}
