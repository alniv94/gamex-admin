<?php

namespace App\Console\Commands;

use App\Services\Legacy\InvestorImport;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

class ImportInvestors extends Command
{
    protected $signature = 'legacy:investors {--dry-run} {--execute} {--verify} {--batch=1000}';

    protected $description = 'Copy and verify investor accounts, hierarchy, referrals, balances and settlement history without replaying payments.';

    public function handle(InvestorImport $import): int
    {
        $modes = (int) (bool) $this->option('dry-run') + (int) (bool) $this->option('execute') + (int) (bool) $this->option('verify');
        $batch = filter_var($this->option('batch'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 5000]]);
        if ($modes > 1 || $batch === false) {
            $this->error('Choose one mode and a batch size between 1 and 5000.');

            return self::FAILURE;
        }
        $lock = Cache::lock('investors:import', 7200);
        if (! $lock->get()) {
            $this->error('An investor import is already running.');

            return self::FAILURE;
        }
        try {
            $report = $import->run((bool) $this->option('execute'), (bool) $this->option('verify'), $batch);
            foreach ($report as $table => $counts) {
                $this->line($table.': source='.$counts['source'].' matching='.$counts['matching'].' missing='.$counts['missing']);
            }
            $this->info($this->option('execute') || $this->option('verify')
                ? 'Investor history verified. Wallets and payments were not replayed.'
                : 'Dry run complete. No investor records changed.');

            return self::SUCCESS;
        } catch (RuntimeException $exception) {
            $this->error($exception instanceof QueryException ? 'Investor import database operation failed. Check schema compatibility and access.' : $exception->getMessage());

            return self::FAILURE;
        } finally {
            $lock->release();
        }
    }
}
