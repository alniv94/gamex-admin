<?php

namespace App\Console\Commands;

use App\Services\Legacy\OtsoPanaloImport;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ImportOtsoPanalo extends Command
{
    protected $signature = 'legacy:otso-panalo {--dry-run : Inspect without writing (default)} {--execute : Copy missing history and verify} {--verify : Compare imported history} {--batch=500 : Rows per batch (1..2000)}';

    protected $description = 'Import and verify Otso Panalo history without source writes or replaying payments.';

    /**
     * Execute the console command.
     */
    public function handle(OtsoPanaloImport $import): int
    {
        $flags = (int) (bool) $this->option('execute') + (int) (bool) $this->option('verify') + (int) (bool) $this->option('dry-run');
        $batch = filter_var($this->option('batch'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 2000]]);
        if ($flags > 1 || $batch === false) {
            $this->error('Choose one mode and a batch size between 1 and 2000.');

            return self::FAILURE;
        }
        $lock = Cache::lock('otso:write', 7200);
        if (! $lock->get()) {
            $this->error('An Otso Panalo update or import is in progress.');

            return self::FAILURE;
        }
        try {
            $report = $import->scan((bool) $this->option('execute'), (bool) $this->option('verify'), $batch, function (string $table, array $counts): void {
                $this->line($table.': source='.$counts['source'].' matching='.$counts['matching'].' missing='.$counts['missing'].' unlinked='.$counts['unlinked']);
            });
            if ($this->option('execute')) {
                $report = $import->scan(false, true, $batch);
            }
            if ($this->option('execute') || $this->option('verify')) {
                DB::table('admin_otso_panalo_imports')->insert(['source' => config('otso.legacy_source'), 'report' => json_encode($report, JSON_THROW_ON_ERROR), 'verified_at' => now()]);
                $this->info('Otso Panalo history verified. No wallet payments were replayed.');
            } else {
                $this->info('Dry run complete. No records changed.');
            }

            return self::SUCCESS;
        } catch (RuntimeException $exception) {
            $this->error($exception instanceof QueryException ? 'The import failed a database integrity check. Check schema and relationships; no source writes were attempted.' : $exception->getMessage());

            return self::FAILURE;
        } finally {
            $lock->release();
        }
    }
}
