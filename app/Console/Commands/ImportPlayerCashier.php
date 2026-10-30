<?php

namespace App\Console\Commands;

use App\Services\Legacy\PlayerCashierImport;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ImportPlayerCashier extends Command
{
    protected $signature = 'legacy:player-cashier {--dry-run} {--execute} {--verify} {--batch=2000}';

    protected $description = 'Copy and verify cash-in and cash-out requests without replaying wallet transactions.';

    public function handle(PlayerCashierImport $import): int
    {
        $modes = (int) (bool) $this->option('dry-run') + (int) (bool) $this->option('execute') + (int) (bool) $this->option('verify');
        $batch = filter_var($this->option('batch'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 5000]]);
        if ($modes > 1 || $batch === false) {
            $this->error('Choose one mode and a batch size between 1 and 5000.');

            return self::FAILURE;
        }
        $lock = Cache::lock('players:cashier-import', 7200);
        if (! $lock->get()) {
            $this->error('A cashier import is already running.');

            return self::FAILURE;
        }
        DB::disableQueryLog();
        DB::connection('legacy')->disableQueryLog();
        try {
            $progress = fn (string $table, array $counts) => $this->line($table.': source='.$counts['source'].' matching='.$counts['matching'].' missing='.$counts['missing'].' unlinked='.$counts['unlinked']);
            if ($this->option('execute')) {
                $this->info('Checking existing records before copying...');
                $import->scan(false, false, $batch);
            }
            $report = $import->scan((bool) $this->option('execute'), (bool) $this->option('verify'), $batch, $progress);
            if ($this->option('execute')) {
                $this->info('Copy completed. Verifying every imported row...');
                $report = $import->scan(false, true, $batch, $progress);
            }
            if ($this->option('execute') || $this->option('verify')) {
                DB::table('admin_player_cashier_imports')->insert([
                    'source' => config('players.legacy_source'), 'report' => json_encode($report, JSON_THROW_ON_ERROR), 'verified_at' => now(),
                ]);
                $this->info('Cashier history verified. Wallet balances and wallet transactions were retained.');
            } else {
                $this->info('Dry run complete. No cashier records changed.');
            }

            return self::SUCCESS;
        } catch (RuntimeException $exception) {
            $this->error($exception instanceof QueryException ? 'Cashier database operation failed. Check schema compatibility and access.' : $exception->getMessage());

            return self::FAILURE;
        } finally {
            $lock->release();
        }
    }
}
