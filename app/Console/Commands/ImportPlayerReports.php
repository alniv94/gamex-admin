<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ImportPlayerReports extends Command
{
    protected $signature = 'legacy:player-reports {--dry-run} {--execute} {--verify} {--batch=1000}';

    protected $description = 'Import and verify Lucky Four amounts used by the active players report.';

    public function handle(): int
    {
        $modes = (int) (bool) $this->option('dry-run') + (int) (bool) $this->option('execute') + (int) (bool) $this->option('verify');
        $batch = filter_var($this->option('batch'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 5000]]);
        if ($modes > 1 || $batch === false) {
            $this->error('Choose one mode and a batch size between 1 and 5000.');

            return self::FAILURE;
        }
        $lock = Cache::lock('players:report-import', 7200);
        if (! $lock->get()) {
            $this->error('A player report import is already running.');

            return self::FAILURE;
        }
        try {
            if ($this->option('execute')) {
                $this->scan(false, false, $batch);
            }
            $count = $this->scan((bool) $this->option('execute'), (bool) $this->option('verify'), $batch);
            if ($this->option('execute')) {
                $count = $this->scan(false, true, $batch);
            }
            if ($this->option('execute') || $this->option('verify')) {
                DB::table('admin_player_report_imports')->insert([
                    'source' => config('players.legacy_source'), 'row_count' => $count, 'verified_at' => now(),
                ]);
                $this->info("Verified {$count} Lucky Four amounts. No wallets or bets were changed.");
            } else {
                $this->info("Dry run complete: {$count} Lucky Four amounts. No records changed.");
            }

            return self::SUCCESS;
        } catch (RuntimeException $exception) {
            $this->error($exception instanceof QueryException ? 'Player report import failed. Check schema compatibility and access.' : $exception->getMessage());

            return self::FAILURE;
        } finally {
            $lock->release();
        }
    }

    private function scan(bool $execute, bool $verify, int $batch): int
    {
        $count = 0;
        DB::connection('legacy')->table('lucky_four_bets')->select(['id', 'ending_bet_id', 'amount'])
            ->chunkById($batch, function ($rows) use ($execute, $verify, &$count): void {
                $existing = DB::table('lucky_four_bets')->whereIn('id', $rows->pluck('id'))->get()->keyBy('id');
                $parents = DB::table('ending_bets')->whereIn('id', $rows->pluck('ending_bet_id'))->get(['id', 'legacy_checksum'])->keyBy('id');
                $inserts = [];
                foreach ($rows as $row) {
                    $parent = $parents->get($row->ending_bet_id);
                    if (! $parent || $parent->legacy_checksum === null) {
                        throw new RuntimeException("Import the historical Ending bet {$row->ending_bet_id} before its Lucky Four amount.");
                    }
                    $data = ['id' => (int) $row->id, 'ending_bet_id' => (int) $row->ending_bet_id,
                        'amount' => $row->amount === null ? null : number_format((float) $row->amount, 2, '.', '')];
                    $data['legacy_checksum'] = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
                    $old = $existing->get($row->id);
                    if ($old && ($old->legacy_checksum !== $data['legacy_checksum'] || (int) $old->ending_bet_id !== $data['ending_bet_id']
                        || ($old->amount === null ? null : number_format((float) $old->amount, 2, '.', '')) !== $data['amount'])) {
                        throw new RuntimeException("Lucky Four import conflict for record {$row->id}. No existing record was overwritten.");
                    }
                    if (! $old) {
                        if ($verify) {
                            throw new RuntimeException("Missing Lucky Four record {$row->id}.");
                        }
                        $inserts[] = $data;
                    }
                    $count++;
                }
                if ($execute && $inserts) {
                    DB::transaction(function () use ($inserts): void {
                        foreach (array_chunk($inserts, 100) as $part) {
                            DB::table('lucky_four_bets')->insert($part);
                        }
                    });
                }
            });
        if ($verify && DB::table('lucky_four_bets')->whereNotNull('legacy_checksum')->count() !== $count) {
            throw new RuntimeException('Imported Lucky Four row count differs from the source.');
        }

        return $count;
    }
}
