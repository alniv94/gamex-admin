<?php

namespace App\Services\Legacy;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class AgentImport
{
    public const TABLES = [
        'ending_agent_types', 'ending_agent_sub_types', 'ending_agents', 'ending_agent_info',
        'ending_agent_players', 'ending_agent_default_commission', 'ending_agent_cashin',
        'ending_agent_logs', 'ending_agent_commission_histories', 'ending_agent_commission_payments',
    ];

    /** @var array<int, int|null> */
    private array $players = [];

    /** @return array<string, array{source: int, matching: int, missing: int}> */
    public function run(bool $execute, bool $verify, int $batch): array
    {
        $this->players = DB::table('legacy_player_mappings')->where('source', config('players.legacy_source'))
            ->get(['legacy_player_id', 'player_id', 'quarantined'])
            ->mapWithKeys(fn ($row) => [(int) $row->legacy_player_id => $row->quarantined ? null : $row->player_id])->all();
        if (! $this->players) {
            throw new RuntimeException('Import player identity mappings before agent records.');
        }
        if (DB::table('admin_agent_imports')->where('source', '!=', config('players.legacy_source'))->exists()) {
            throw new RuntimeException('Agent records belong to a different legacy source.');
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
                DB::table('admin_agent_imports')->insert([
                    'source' => config('players.legacy_source'), 'report' => json_encode($report, JSON_THROW_ON_ERROR), 'verified_at' => now(),
                ]);

                return $report;
            });
        } finally {
            if ($mysql) {
                $source->statement('ROLLBACK');
            }
        }
    }

    /** @return array<string, array{source: int, matching: int, missing: int}> */
    private function scan(bool $execute, bool $verify, int $batch): array
    {
        $report = [];
        foreach (self::TABLES as $table) {
            $key = match ($table) {
                'ending_agent_info' => 'agent_id', 'ending_agent_players' => 'player_id', default => 'id',
            };
            $targetKey = $table === 'ending_agent_players' ? 'legacy_player_id' : $key;
            $counts = ['source' => DB::connection('legacy')->table($table)->count(), 'matching' => 0, 'missing' => 0];
            DB::connection('legacy')->table($table)->chunkById($batch, function ($records) use ($table, $key, $targetKey, $execute, $verify, &$counts): void {
                $existing = DB::table('admin_'.$table)->whereIn($targetKey, $records->pluck($key))->get()->keyBy($targetKey);
                $inserts = [];
                foreach ($records as $record) {
                    $raw = (array) $record;
                    unset($raw['login_key']);
                    $mapped = $this->map($raw);
                    $old = $existing->get($raw[$key]);
                    if ($old) {
                        if (! $this->matches($table, $mapped, (array) $old)) {
                            throw new RuntimeException("Agent import conflict in {$table}, key {$raw[$key]}. Existing records were retained.");
                        }
                        $counts['matching']++;
                    } else {
                        if ($verify) {
                            throw new RuntimeException("Missing agent record in {$table}, key {$raw[$key]}.");
                        }
                        $inserts[] = $mapped;
                        $counts['missing']++;
                    }
                }
                if ($execute) {
                    foreach (array_chunk($inserts, 50) as $part) {
                        DB::table('admin_'.$table)->insert($part);
                    }
                }
            }, $key);
            if (DB::table('admin_'.$table)->whereNotNull('legacy_checksum')->count() !== $counts['matching'] + ($execute ? $counts['missing'] : 0)) {
                throw new RuntimeException("Source row count changed for {$table}. Existing records were retained.");
            }
            $report[$table] = $counts;
        }

        return $report;
    }

    /** @param array<string, mixed> $raw
     * @return array<string, mixed>
     */
    private function map(array $raw): array
    {
        ksort($raw);
        $mapped = $raw;
        if (array_key_exists('player_id', $raw)) {
            $id = (int) $raw['player_id'];
            if ($id > 0 && ! array_key_exists($id, $this->players)) {
                throw new RuntimeException("Missing identity mapping for legacy player {$id}.");
            }
            $mapped['legacy_player_id'] = $raw['player_id'];
            $mapped['player_id'] = $id > 0 ? $this->players[$id] : null;
        }
        $dates = [];
        foreach ($mapped as $column => $value) {
            if ($value !== null && (str_ends_with($column, '_at') || in_array($column, ['bet_start_date', 'bet_end_date'], true))) {
                if (str_starts_with((string) $value, '0000-')) {
                    $dates[$column] = $value;
                    $mapped[$column] = null;
                } elseif (str_ends_with($column, '_at')) {
                    $mapped[$column] = CarbonImmutable::parse((string) $value, 'Asia/Manila')->utc()->format('Y-m-d H:i:s');
                }
            }
        }

        return $mapped + [
            'legacy_checksum' => hash('sha256', json_encode($raw, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)),
            'legacy_dates' => $dates ? json_encode($dates, JSON_THROW_ON_ERROR) : null,
        ];
    }

    /** @param array<string, mixed> $expected
     * @param  array<string, mixed>  $actual
     */
    private function matches(string $table, array $expected, array $actual): bool
    {
        $mutable = match ($table) {
            'ending_agents' => ['outlet_id', 'type_id', 'username', 'password', 'is_active', 'commission', 'wallet', 'type'],
            'ending_agent_info' => ['first_name', 'middle_name', 'last_name', 'mobile_number', 'email_address', 'citymun_id', 'sub_type', 'page_name'],
            'ending_agent_cashin' => ['status', 'evaluated_at', 'evaluated_by', 'remarks'],
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
            } elseif (in_array($column, ['commission', 'commission_amount', 'amount'], true)) {
                if (number_format((float) $value, 2, '.', '') !== number_format((float) ($actual[$column] ?? 0), 2, '.', '')) {
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
