<?php

namespace App\Console\Commands;

use App\Services\Legacy\PlayerManagementImport;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ImportPlayerManagement extends Command
{
    protected $signature = 'legacy:player-management {--dry-run} {--execute} {--verify} {--batch=2000}';

    protected $description = 'Copy and verify player settings, verification metadata, fund accounts, and activity history through existing identity mappings.';

    public function handle(PlayerManagementImport $import): int
    {
        $modes = (int) (bool) $this->option('dry-run') + (int) (bool) $this->option('execute') + (int) (bool) $this->option('verify');
        $batch = filter_var($this->option('batch'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 5000]]);
        if ($modes > 1 || $batch === false) {
            $this->error('Choose one mode and a batch size between 1 and 5000.');

            return self::FAILURE;
        }
        $lock = Cache::lock('players:import', 7200);
        if (! $lock->get()) {
            $this->error('A player management import is already running.');

            return self::FAILURE;
        }
        DB::disableQueryLog();
        DB::connection('legacy')->disableQueryLog();
        try {
            $report = $import->scan((bool) $this->option('execute'), (bool) $this->option('verify'), $batch,
                fn (string $table, array $counts) => $this->line($table.': source='.$counts['source'].' matching='.$counts['matching'].' missing='.$counts['missing']));
            if ($this->option('execute')) {
                $this->info('Copy completed. Verifying every imported row...');
                $report = $import->scan(false, true, $batch);
            }
            if ($this->option('execute') || $this->option('verify')) {
                DB::table('admin_player_imports')->insert([
                    'source' => config('players.legacy_source'), 'report' => json_encode($report, JSON_THROW_ON_ERROR), 'verified_at' => now(),
                ]);
                $this->info('Player management history verified. Existing identities, wallets, and wallet transactions were retained.');
            } else {
                $this->info('Dry run complete. No player management records changed.');
            }

            return self::SUCCESS;
        } catch (RuntimeException $exception) {
            $this->error($exception instanceof QueryException ? 'Player management database operation failed. Check schema compatibility and access.' : $exception->getMessage());

            return self::FAILURE;
        } finally {
            $lock->release();
        }
    }
}
