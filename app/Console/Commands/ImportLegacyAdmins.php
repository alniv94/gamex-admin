<?php

namespace App\Console\Commands;

use App\Services\Legacy\AdminImport;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

class ImportLegacyAdmins extends Command
{
    protected $signature = 'legacy:import-admins {--dry-run : Inspect without writing (the default)} {--execute : Import after a complete preflight}';

    protected $description = 'Import legacy administrators, profiles, and permissions without changing the source.';

    public function handle(AdminImport $import): int
    {
        if ($this->option('execute') && $this->option('dry-run')) {
            $this->error('Choose either --execute or --dry-run.');

            return self::FAILURE;
        }
        $lock = Cache::lock('admin:legacy-import', 300);
        if (! $lock->get()) {
            $this->error('Another administrator import is running.');

            return self::FAILURE;
        }
        try {
            $plan = $import->plan();
            $report = $import->inspect($plan);
            $this->table(array_keys($report), [array_values($report)]);
            if ($this->option('execute')) {
                $import->execute($plan);
                $verified = $import->inspect($plan);
                $this->info('Import committed and verified: '.$verified['existing'].' administrators.');
            } else {
                $this->info('Dry run complete. No account records changed. Use --execute to import.');
            }
            if ($report['email_warnings']) {
                $this->warn('Non-unique or invalid emails remain in the profile and encrypted archive; users.email is null for those accounts.');
            }

            return self::SUCCESS;
        } catch (RuntimeException $exception) {
            // Never print SQL exception bindings, credentials, or raw account data.
            $this->error($exception instanceof QueryException ?
                'Database operation failed. Check connection access and schema compatibility.' : $exception->getMessage());

            return self::FAILURE;
        } finally {
            $lock->release();
        }
    }
}
