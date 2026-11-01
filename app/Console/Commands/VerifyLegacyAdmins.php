<?php

namespace App\Console\Commands;

use App\Services\Legacy\AdminImport;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use RuntimeException;

class VerifyLegacyAdmins extends Command
{
    protected $signature = 'legacy:verify-admins';

    protected $description = 'Verify administrator source archives and imported accounts not yet managed through Settings.';

    public function handle(AdminImport $import): int
    {
        try {
            $report = $import->inspect($import->plan());
            $this->table(array_keys($report), [array_values($report)]);
            if ($report['new'] !== 0 || $report['pending_catalog'] !== 0 || $report['pending_archives'] !== 0) {
                $this->error('Some source administrators have not been imported.');

                return self::FAILURE;
            }
            $this->info('Every source administrator has a verified archive. Unmanaged accounts also match their imported identity and access.');
            if ($report['managed'] > 0) {
                $this->info($report['managed'].' accounts have been managed through Settings; their current identity and access are not compared with the legacy baseline.');
            }

            return self::SUCCESS;
        } catch (RuntimeException $exception) {
            $this->error($exception instanceof QueryException ?
                'Database operation failed. Check connection access and schema compatibility.' : $exception->getMessage());

            return self::FAILURE;
        }
    }
}
