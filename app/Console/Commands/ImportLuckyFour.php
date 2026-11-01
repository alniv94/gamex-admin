<?php

namespace App\Console\Commands;

use App\Services\Legacy\LuckyFourImport;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ImportLuckyFour extends Command
{
    protected $signature = 'legacy:lucky-four {--dry-run : Inspect without writing (default)} {--execute : Import and verify history} {--verify : Compare imported history} {--batch=500 : Rows per batch (1..2000)}';

    protected $description = 'Complete Lucky Four history, preserving report imports and historical payments.';

    public function handle(LuckyFourImport $import): int
    {
        $modes = (int) (bool) $this->option('execute') + (int) (bool) $this->option('verify') + (int) (bool) $this->option('dry-run');
        $batch = filter_var($this->option('batch'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 2000]]);
        if ($modes > 1 || $batch === false) {
            $this->error('Choose one mode and a batch size between 1 and 2000.');

            return self::FAILURE;
        }
        $locks = [];
        try {
            foreach (['ending:write', 'players:report-import'] as $key) {
                $lock = Cache::lock($key, 7200);
                if (! $lock->get()) {
                    throw new RuntimeException('An Ending, Lucky Four, or player report update is in progress.');
                }
                $locks[] = $lock;
            }
            $progress = function (string $table, array $counts): void {
                $this->line($table.': source='.$counts['source'].' matching='.$counts['matching'].' missing='.$counts['missing'].' upgrading='.$counts['upgrading']);
            };
            if ($this->option('execute')) {
                $import->scan(false, false, $batch);
                $report = DB::transaction(function () use ($import, $batch, $progress): array {
                    $import->scan(true, false, $batch, $progress);

                    return $import->scan(false, true, $batch);
                });
                $import->preserveSequences();
            } else {
                $report = $import->scan(false, (bool) $this->option('verify'), $batch, $progress);
            }
            if ($this->option('execute') || $this->option('verify')) {
                DB::table('admin_lucky_four_imports')->insert(['source' => config('ending.legacy_source'), 'report' => json_encode($report, JSON_THROW_ON_ERROR), 'verified_at' => now()]);
                DB::table('admin_player_report_imports')->insert(['source' => config('players.legacy_source'), 'row_count' => $report['lucky_four_bets']['source'], 'verified_at' => now()]);
                $this->info('Lucky Four history verified. Historical payments were not replayed.');
            } else {
                $this->info('Dry run complete. No records changed.');
            }

            return self::SUCCESS;
        } catch (RuntimeException $exception) {
            $this->error($exception instanceof QueryException ? 'Lucky Four import failed a database integrity check. Check schema, duplicate keys and relationships.' : $exception->getMessage());

            return self::FAILURE;
        } finally {
            foreach (array_reverse($locks) as $lock) {
                $lock->release();
            }
        }
    }
}
