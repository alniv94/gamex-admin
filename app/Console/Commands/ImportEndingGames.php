<?php

namespace App\Console\Commands;

use App\Services\Legacy\EndingImport;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ImportEndingGames extends Command
{
    protected $signature = 'legacy:ending {--dry-run : Inspect without writing (default)} {--execute : Copy missing historical records} {--verify : Compare all imported records} {--batch=1000 : Rows per source batch (1..2000)}';

    protected $description = 'Copy and verify ending game history, cards, bets, prizes, winners, commissions and logs.';

    public function handle(EndingImport $import): int
    {
        $flags = (int) (bool) $this->option('execute') + (int) (bool) $this->option('verify') + (int) (bool) $this->option('dry-run');
        $batch = filter_var($this->option('batch'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 2000]]);
        if ($flags > 1 || $batch === false) {
            $this->error('Choose one mode and a batch size between 1 and 2000.');

            return self::FAILURE;
        }
        $lock = Cache::lock('ending:write', 7200);
        if (! $lock->get()) {
            $this->error('Ending games are being updated or imported. Try again after that operation finishes.');

            return self::FAILURE;
        }
        DB::disableQueryLog();
        DB::connection('legacy')->disableQueryLog();
        try {
            $report = $import->scan((bool) $this->option('execute'), (bool) $this->option('verify'), $batch,
                function (string $table, array $counts): void {
                    $this->line($table.': source='.$counts['source'].' matching='.$counts['existing'].' missing='.$counts['missing']);
                });
            if ($this->option('execute')) {
                $import->preserveSequences();
                $this->info('Copy completed. Comparing every imported record before enabling game creation...');
                $report = $import->scan(false, true, $batch);
            }
            if ($this->option('execute') || $this->option('verify')) {
                DB::table('admin_ending_imports')->insert([
                    'source' => config('ending.legacy_source'), 'report' => json_encode($report, JSON_THROW_ON_ERROR), 'verified_at' => now(),
                ]);
                $this->info('Ending history verified. No wallet balances or transactions were replayed.');
            } else {
                $this->info('Dry run complete; no ending records changed.');
            }

            return self::SUCCESS;
        } catch (RuntimeException $exception) {
            $this->error($exception instanceof QueryException ? 'Ending database operation failed. Check schema compatibility and access; no source writes were attempted.' : $exception->getMessage());

            return self::FAILURE;
        } finally {
            $lock->release();
        }
    }
}
