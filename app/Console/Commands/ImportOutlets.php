<?php

namespace App\Console\Commands;

use App\Services\Legacy\OutletImport;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

class ImportOutlets extends Command
{
    protected $signature = 'legacy:outlets {--dry-run} {--execute} {--verify} {--batch=1000}';

    protected $description = 'Copy and verify legacy outlets, preserving IDs, locations, status and creation dates.';

    public function handle(OutletImport $import): int
    {
        $modes = (int) (bool) $this->option('dry-run') + (int) (bool) $this->option('execute') + (int) (bool) $this->option('verify');
        $batch = filter_var($this->option('batch'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 5000]]);
        if ($modes > 1 || $batch === false) {
            $this->error('Choose one mode and a batch size between 1 and 5000.');

            return self::FAILURE;
        }
        $lock = Cache::lock('investors:import', 7200);
        if (! $lock->get()) {
            $this->error('An outlet or investor import is already running.');

            return self::FAILURE;
        }
        try {
            $report = $import->run((bool) $this->option('execute'), (bool) $this->option('verify'), $batch);
            $this->line('outlets: source='.$report['source'].' matching='.$report['matching'].' missing='.$report['missing']);
            $this->info($this->option('execute') || $this->option('verify') ? 'Legacy outlets verified.' : 'Dry run complete. No outlet records changed.');

            return self::SUCCESS;
        } catch (RuntimeException $exception) {
            $this->error($exception instanceof QueryException ? 'Outlet import failed. Check database access and schema compatibility.' : $exception->getMessage());

            return self::FAILURE;
        } finally {
            $lock->release();
        }
    }
}
