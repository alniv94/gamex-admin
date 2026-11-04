<?php

namespace App\Services\Legacy;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class OutletImport
{
    /** @return array{source: int, matching: int, missing: int} */
    public function run(bool $execute, bool $verify, int $batch): array
    {
        foreach (['admin_outlet_imports', 'admin_investor_imports'] as $table) {
            if (DB::table($table)->where('source', '!=', config('players.legacy_source'))->exists()) {
                throw new RuntimeException('Outlet records belong to a different legacy source.');
            }
        }
        $source = DB::connection('legacy');
        $mysql = $source->getDriverName() === 'mysql';
        if ($mysql) {
            $source->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $source->statement('START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY');
        }
        try {
            $report = $this->scan(false, $verify, $batch);
            if (! $execute) {
                return $report;
            }

            return DB::transaction(function () use ($batch): array {
                $this->scan(true, false, $batch);
                $report = $this->scan(false, true, $batch);
                DB::table('admin_outlet_imports')->insert([
                    'source' => config('players.legacy_source'), 'report' => json_encode($report, JSON_THROW_ON_ERROR),
                    'verified_at' => now(),
                ]);

                return $report;
            });
        } finally {
            if ($mysql) {
                $source->statement('ROLLBACK');
            }
        }
    }

    /** @return array{source: int, matching: int, missing: int} */
    private function scan(bool $execute, bool $verify, int $batch): array
    {
        $source = DB::connection('legacy');
        $counts = ['source' => $source->table('outlets')->count(), 'matching' => 0, 'missing' => 0];
        $source->table('outlets')->chunkById($batch, function ($records) use ($execute, $verify, &$counts): void {
            $existing = DB::table('admin_outlets')->whereIn('id', $records->pluck('id'))->get()->keyBy('id');
            $barangays = DB::table('barangays')->whereIn('id', $records->pluck('barangay_id')->filter())
                ->pluck('id')->map(fn ($id) => (int) $id)->all();
            foreach ($records as $record) {
                $raw = (array) $record;
                ksort($raw);
                $mapped = $raw + ['legacy_checksum' => hash('sha256', json_encode($raw, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)), 'legacy_dates' => null];
                if ($raw['created_at'] !== null) {
                    if (str_starts_with((string) $raw['created_at'], '0000-')) {
                        $mapped['created_at'] = null;
                        $mapped['legacy_dates'] = json_encode(['created_at' => $raw['created_at']], JSON_THROW_ON_ERROR);
                    } else {
                        $mapped['created_at'] = CarbonImmutable::parse((string) $raw['created_at'], 'Asia/Manila')->utc()->format('Y-m-d H:i:s');
                    }
                }
                if ($raw['barangay_id'] !== null && ! in_array((int) $raw['barangay_id'], $barangays, true)) {
                    throw new RuntimeException("Import geographic reference data before outlet {$raw['id']}. Missing barangay {$raw['barangay_id']}.");
                }
                $old = $existing->get($raw['id']);
                if ($old) {
                    if (! $this->matchesBaseline($mapped, (array) $old)) {
                        throw new RuntimeException("Outlet import conflict at ID {$raw['id']}. Existing records were retained.");
                    }
                    $counts['matching']++;
                } else {
                    if ($verify) {
                        throw new RuntimeException("Missing outlet ID {$raw['id']} in the target database.");
                    }
                    if ($execute) {
                        DB::table('admin_outlets')->insert($mapped);
                    }
                    $counts['missing']++;
                }
            }
        });
        $expected = $counts['matching'] + ($execute ? $counts['missing'] : 0);
        if (DB::table('admin_outlets')->whereNotNull('legacy_checksum')->count() !== $expected) {
            throw new RuntimeException('The source outlet row count changed. Existing records were retained.');
        }

        return $counts;
    }

    /**
     * @param  array<string, mixed>  $expected
     * @param  array<string, mixed>  $actual
     */
    public function matchesBaseline(array $expected, array $actual): bool
    {
        foreach ($expected as $column => $value) {
            if (($actual['management_version'] ?? 0) > 0 && in_array($column, ['outlet_name', 'barangay_id', 'address', 'is_active'], true)) {
                continue;
            }
            if ($column === 'legacy_dates') {
                if (json_decode($value ?? 'null', true) != json_decode($actual[$column] ?? 'null', true)) {
                    return false;
                }
            } elseif (($value === null) !== (($actual[$column] ?? null) === null)
                || ($value !== null && (string) $value !== (string) ($actual[$column] ?? ''))) {
                return false;
            }
        }

        return true;
    }
}
