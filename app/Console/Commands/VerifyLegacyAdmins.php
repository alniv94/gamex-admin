<?php

namespace App\Console\Commands;

use App\Services\Legacy\AdminImport;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use RuntimeException;

class VerifyLegacyAdmins extends Command
{
    protected $signature = 'legacy:verify-admins';

    protected $description = 'Verify imported administrator identities, hashes, profiles, status, archives, and permission assignments.';

    public function handle(AdminImport $import): int
    {
        try {
            $report = $import->inspect($import->plan());
            $this->table(array_keys($report), [array_values($report)]);
            if ($report['new'] !== 0 || $report['pending_catalog'] !== 0 || $report['pending_archives'] !== 0) {
                $this->error('Some source administrators have not been imported.');

                return self::FAILURE;
            }
            $this->info('Every source administrator matches the target.');

            return self::SUCCESS;
        } catch (RuntimeException $exception) {
            $this->error($exception instanceof QueryException ?
                'Database operation failed. Check connection access and schema compatibility.' : $exception->getMessage());

            return self::FAILURE;
        }
    }
}
