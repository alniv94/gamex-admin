<?php

namespace App\Services\Legacy;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class PlayerManagementImport
{
    public const TABLES = [
        'players' => 'admin_player_legacy_states',
        'player_verification_details' => 'admin_player_verifications',
        'player_reject_reasons' => 'admin_player_reject_reasons',
        'banks' => 'admin_player_banks',
        'bank_accounts' => 'admin_player_fund_accounts',
        'admin_user_logs' => 'admin_player_legacy_logs',
        'player_logs' => 'admin_player_logs',
    ];

    /** @var array<int, int|null> */
    private array $players = [];

    public function sourceQuery(string $table): Builder
    {
        if (! isset(self::TABLES[$table])) {
            throw new RuntimeException('Unsupported player management source table.');
        }
        $query = DB::connection('legacy')->table($table);
        if ($table === 'players') {
            $query->select(['id', 'account_verified', 'betting', 'cashin', 'cashout', 'is_active']);
        }
        if ($table === 'admin_user_logs') {
            $query->where('player_id', '>', 0);
        }

        return $query;
    }

    /** @return array<string, array{source: int, matching: int, missing: int}> */
    public function scan(bool $execute, bool $verify, int $batch, ?callable $progress = null): array
    {
        $this->players = DB::table('legacy_player_mappings')->where('source', config('players.legacy_source'))
            ->get(['legacy_player_id', 'player_id', 'quarantined'])
            ->mapWithKeys(fn ($row) => [(int) $row->legacy_player_id => $row->quarantined ? null : $row->player_id])->all();
        if (! $this->players) {
            throw new RuntimeException('Import player identity mappings before player management history.');
        }
        $source = DB::connection('legacy');
        $mysql = $source->getDriverName() === 'mysql';
        if ($mysql) {
            $source->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $source->statement('START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY');
        }
        $report = [];
        try {
            foreach (self::TABLES as $table => $target) {
                $sourceKey = $table === 'player_verification_details' ? 'player_id' : 'id';
                $targetKey = $table === 'players' ? 'legacy_player_id' : 'id';
                $counts = ['source' => $this->sourceQuery($table)->count(), 'matching' => 0, 'missing' => 0];
                $this->sourceQuery($table)->chunkById($batch, function ($records) use ($table, $target, $sourceKey, $targetKey, $execute, $verify, &$counts): void {
                    $existing = DB::table($target)->whereIn($targetKey, $records->pluck($sourceKey))->get()->keyBy($targetKey);
                    $inserts = [];
                    foreach ($records as $record) {
                        $raw = (array) $record;
                        $mapped = $this->map($table, $raw);
                        $old = $existing->get($raw[$sourceKey]);
                        if ($old) {
                            if (! $this->matches($table, $mapped, (array) $old)) {
                                throw new RuntimeException("Player management conflict in {$table}, key {$raw[$sourceKey]}. Existing data was not overwritten.");
                            }
                            $counts['matching']++;
                        } else {
                            if ($verify) {
                                throw new RuntimeException("Missing imported player management row in {$table}, key {$raw[$sourceKey]}.");
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
                }, $sourceKey);
                if (($execute || $verify) && DB::table($target)->whereNotNull('legacy_checksum')->count() !== $counts['source']) {
                    throw new RuntimeException("Imported row count differs from source for {$table}.");
                }
                if ($execute && $table === 'players') {
                    $this->initializeControls();
                }
                $report[$table] = $counts;
                if ($progress) {
                    $progress($table, $counts);
                }
            }

            return $report;
        } finally {
            if ($mysql) {
                $source->statement('ROLLBACK');
            }
        }
    }

    private function initializeControls(): void
    {
        foreach (['betting', 'cashin', 'cashout'] as $column) {
            DB::table('players')->whereNull($column)->whereExists(fn (Builder $query) => $query
                ->selectRaw('1')->from('admin_player_legacy_states as s')->whereColumn('s.player_id', 'players.id'))
                ->update([$column => DB::raw("(SELECT CASE WHEN s.{$column} = 'Enabled' THEN 'Enabled' ELSE 'Disabled' END FROM admin_player_legacy_states s WHERE s.player_id = players.id)")]);
        }
    }

    /** @param array<string, mixed> $raw
     * @return array<string, mixed>
     */
    private function map(string $table, array $raw): array
    {
        ksort($raw);
        $checksum = hash('sha256', json_encode($raw, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        $mapped = $raw;
        if ($table === 'players') {
            if (! array_key_exists($raw['id'], $this->players)) {
                throw new RuntimeException("Missing identity mapping for legacy player {$raw['id']}. Import player identities before management history.");
            }
            $mapped['legacy_player_id'] = (int) $raw['id'];
            $mapped['player_id'] = $this->players[$raw['id']] ?? null;
            unset($mapped['id']);

            return $mapped + ['legacy_checksum' => $checksum];
        }
        if (array_key_exists('player_id', $raw)) {
            $mapped['legacy_player_id'] = $raw['player_id'];
            $mapped['player_id'] = $this->players[$raw['player_id']] ?? null;
            if ($table === 'player_verification_details') {
                $mapped['id'] = $raw['player_id'];
            }
        }
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

        return $mapped + ['legacy_checksum' => $checksum, 'legacy_dates' => $dates ? json_encode($dates, JSON_THROW_ON_ERROR) : null];
    }

    /** @param array<string, mixed> $expected
     * @param  array<string, mixed>  $actual
     */
    private function matches(string $table, array $expected, array $actual): bool
    {
        $mutable = match ($table) {
            'bank_accounts' => ['is_verified', 'is_default', 'updated_at'],
            'player_verification_details' => ['processing_by', 'processing_at', 'player_update', 'verified_by', 'verified_at'],
            default => [],
        };
        foreach ($expected as $column => $value) {
            if (in_array($column, $mutable, true)) {
                continue;
            }
            if ($column === 'legacy_dates') {
                if (json_decode($value ?? 'null', true) != json_decode($actual[$column] ?? 'null', true)) {
                    return false;
                }
            } elseif (! array_key_exists($column, $actual) || ($value === null) !== ($actual[$column] === null)
                || ($value !== null && (string) $value !== (string) $actual[$column])) {
                return false;
            }
        }

        return true;
    }
}
