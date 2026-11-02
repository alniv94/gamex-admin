<?php

namespace App\Services\Legacy;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class InvestorImport
{
    public const TABLES = [
        'outlets', 'investor_types', 'investor_sub_types', 'investor_log_types', 'investors',
        'investor_sub_agents', 'investor_sub_agent_players', 'investor_sub_users', 'investor_referral_links',
        'investor_settled_payouts', 'investor_settled_commissions', 'investor_downline_settled_commission',
        'investor_special_settlement', 'investor_special_settlement_logs', 'investor_payouts',
        'investor_payout_logs', 'investor_commissions', 'investor_sales_representative_commissions',
        'investor_logs', 'investor_wallet_movements',
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
            throw new RuntimeException('Import player identity mappings before investor history.');
        }
        if (DB::table('admin_investor_imports')->where('source', '!=', config('players.legacy_source'))->exists()) {
            throw new RuntimeException('Investor history belongs to a different legacy source.');
        }
        $source = DB::connection('legacy');
        $mysql = $source->getDriverName() === 'mysql';
        if ($mysql) {
            $source->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $source->statement('START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY');
        }
        try {
            $report = $this->scan(false, $verify, $batch);
            if ($execute) {
                return DB::transaction(function () use ($batch): array {
                    $this->scan(true, false, $batch);
                    $report = $this->scan(false, true, $batch);
                    DB::table('admin_investor_imports')->insert([
                        'source' => config('players.legacy_source'), 'report' => json_encode($report, JSON_THROW_ON_ERROR), 'verified_at' => now(),
                    ]);

                    return $report;
                });
            }

            return $report;
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
            $target = 'admin_'.$table;
            $counts = ['source' => DB::connection('legacy')->table($table)->count(), 'matching' => 0, 'missing' => 0];
            DB::connection('legacy')->table($table)->chunkById($batch, function ($records) use ($table, $target, $execute, $verify, &$counts): void {
                $existing = DB::table($target)->whereIn('id', $records->pluck('id'))->get()->keyBy('id');
                $betDates = in_array($table, ['investor_commissions', 'investor_sales_representative_commissions'], true)
                    ? DB::connection('legacy')->table('ending_bets')->whereIn('id', $records->pluck('bet_id')->filter())->pluck('created_at', 'id')->all() : [];
                $inserts = [];
                foreach ($records as $record) {
                    $raw = (array) $record;
                    unset($raw['login_key']);
                    if (in_array($table, ['investor_commissions', 'investor_sales_representative_commissions'], true)) {
                        $raw['bet_created_at'] = $betDates[$raw['bet_id']] ?? null;
                    }
                    $mapped = $this->map($raw);
                    $old = $existing->get($raw['id']);
                    if ($old) {
                        if (! $this->matches($table, $mapped, (array) $old)) {
                            throw new RuntimeException("Investor import conflict in {$table}, key {$raw['id']}. Existing records were retained.");
                        }
                        $counts['matching']++;
                    } else {
                        if ($verify) {
                            throw new RuntimeException("Missing investor history in {$table}, key {$raw['id']}.");
                        }
                        $inserts[] = $mapped;
                        $counts['missing']++;
                    }
                }
                if ($execute) {
                    foreach (array_chunk($inserts, 50) as $part) {
                        DB::table($target)->insert($part);
                    }
                }
            });
            $imported = DB::table($target)->whereNotNull('legacy_checksum')->count();
            if ($imported !== $counts['matching'] + ($execute ? $counts['missing'] : 0)) {
                throw new RuntimeException("Source row count changed for {$table}. No existing records were overwritten.");
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
        $checksum = hash('sha256', json_encode($raw, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        $mapped = $raw;
        foreach (['transfer_to', 'requestor_id'] as $column) {
            if (array_key_exists($column, $raw)) {
                $mapped['legacy_'.$column] = $raw[$column];
            }
        }
        foreach (['player_id', ...(($raw['type'] ?? '') === 'Player' ? ['transfer_to', 'requestor_id'] : [])] as $column) {
            if (array_key_exists($column, $raw)) {
                $id = (int) $raw[$column];
                if ($id > 0 && ! array_key_exists($id, $this->players)) {
                    throw new RuntimeException("Missing identity mapping for legacy player {$id}.");
                }
                $mapped['legacy_'.$column] = $raw[$column];
                $mapped[$column] = $id > 0 ? $this->players[$id] : null;
            }
        }
        $dates = [];
        foreach ($mapped as $column => $value) {
            if ($value !== null && (str_ends_with($column, '_at') || $column === 'process_date')) {
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
            'investors' => ['outlet_id', 'territory', 'first_name', 'last_name', 'mobile_number', 'password', 'base_commission', 'commission', 'sub_commission', 'category', 'wallet', 'link_type', 'status'],
            'investor_sub_users' => ['first_name', 'last_name', 'mobile_number', 'password', 'status'],
            'investor_payouts' => ['status', 'is_settled', 'settlemet_id'],
            'investor_commissions', 'investor_sales_representative_commissions' => ['settlement_id', 'is_settled', 'is_paid'],
            'investor_special_settlement' => ['status', 'mode_of_payment', 'reference', 'remarks'],
            default => [],
        };
        foreach ($expected as $column => $value) {
            if (in_array($column, $mutable, true)) {
                continue;
            }
            if (in_array($column, ['amount', 'token', 'commission', 'commission_percent', 'commission_amount'], true) && $value !== null && ($actual[$column] ?? null) !== null) {
                $normalize = fn ($number) => str_contains((string) $number, '.') ? rtrim(rtrim((string) $number, '0'), '.') : (string) $number;
                if ($normalize($value) !== $normalize($actual[$column])) {
                    return false;
                }

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
