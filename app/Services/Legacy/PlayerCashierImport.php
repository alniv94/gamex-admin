<?php

namespace App\Services\Legacy;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class PlayerCashierImport
{
    public const TABLES = ['player_cashin', 'player_cashout'];

    /** @return array<string, array{source: int, matching: int, missing: int, unlinked: int}> */
    public function scan(bool $execute, bool $verify, int $batch, ?callable $progress = null): array
    {
        $mappings = DB::table('legacy_player_mappings')->where('source', config('players.legacy_source'))
            ->get(['id', 'legacy_player_id', 'player_id', 'quarantined'])->keyBy('legacy_player_id');
        if ($mappings->isEmpty()) {
            throw new RuntimeException('Import player identities before cashier history.');
        }
        $source = DB::connection('legacy');
        $mysql = $source->getDriverName() === 'mysql';
        if ($mysql) {
            $source->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $source->statement('START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY');
        }
        $report = [];
        try {
            foreach (self::TABLES as $table) {
                $counts = ['source' => $source->table($table)->count(), 'matching' => 0, 'missing' => 0, 'unlinked' => 0];
                $source->table($table)->chunkById($batch, function ($records) use ($table, $execute, $verify, $mappings, &$counts): void {
                    $existing = DB::table($table)->whereIn('id', $records->pluck('id'))->get()->keyBy('id');
                    $inserts = [];
                    foreach ($records as $record) {
                        $raw = (array) $record;
                        $mapping = $mappings->get($raw['player_id']);
                        if (! $mapping) {
                            throw new RuntimeException("Missing identity mapping in {$table}, key {$raw['id']}.");
                        }
                        ksort($raw);
                        $mapped = $raw;
                        $mapped['legacy_checksum'] = hash('sha256', json_encode($raw, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
                        $mapped['legacy_player_id'] = $raw['player_id'];
                        $mapped['legacy_player_mapping_id'] = $mapping->id;
                        $mapped['player_id'] = $mapping->quarantined ? null : $mapping->player_id;
                        if ($mapped['player_id'] === null) {
                            $counts['unlinked']++;
                        }
                        $dates = [];
                        foreach (['created_at', 'evaluated_at'] as $column) {
                            if (! empty($raw[$column])) {
                                if (str_starts_with($raw[$column], '0000-')) {
                                    $dates[$column] = $raw[$column];
                                    $mapped[$column] = null;
                                } else {
                                    $mapped[$column] = CarbonImmutable::parse($raw[$column], 'Asia/Manila')->utc()->format('Y-m-d H:i:s');
                                }
                            }
                        }
                        $mapped['legacy_dates'] = $dates ? json_encode($dates, JSON_THROW_ON_ERROR) : null;
                        $old = $existing->get($raw['id']);
                        if ($old) {
                            foreach ($mapped as $column => $value) {
                                if (in_array($column, ['status', 'evaluated_by', 'evaluated_at', 'remarks'], true) && $old->legacy_checksum !== null) {
                                    continue;
                                }
                                $actual = $old->{$column};
                                if ($column === 'amount') {
                                    $value = rtrim(rtrim(str_contains((string) $value, '.') ? (string) $value : $value.'.00', '0'), '.');
                                    $actual = rtrim(rtrim(str_contains((string) $actual, '.') ? (string) $actual : $actual.'.00', '0'), '.');
                                }
                                $matches = $column === 'legacy_dates'
                                    ? json_decode($value ?? 'null', true) === json_decode($actual ?? 'null', true)
                                    : (($value === null) === ($actual === null) && ($value === null || (string) $value === (string) $actual));
                                if (! $matches) {
                                    throw new RuntimeException("Cashier import conflict in {$table}, key {$raw['id']}. Existing records were not overwritten.");
                                }
                            }
                            $counts['matching']++;
                        } else {
                            if ($verify) {
                                throw new RuntimeException("Missing cashier history in {$table}, key {$raw['id']}.");
                            }
                            $inserts[] = $mapped;
                            $counts['missing']++;
                        }
                    }
                    if ($execute && $inserts) {
                        DB::transaction(function () use ($table, $inserts): void {
                            foreach (array_chunk($inserts, 100) as $part) {
                                DB::table($table)->insert($part);
                            }
                        });
                    }
                });
                if (($execute || $verify) && DB::table($table)->whereNotNull('legacy_checksum')->count() !== $counts['source']) {
                    throw new RuntimeException("Imported row count differs from source for {$table}.");
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
}
