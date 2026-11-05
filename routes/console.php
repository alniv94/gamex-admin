<?php

use App\Services\Ending\EndingWorkflow;
use App\Services\SummaryReports;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('ending:close-betting', function (EndingWorkflow $workflow) {
    $this->info('Closed '.$workflow->closeDue().' ending games at their betting cutoff.');
})->purpose('Close betting on new ending games when their configured cutoff is reached');

Schedule::command('ending:close-betting')->everyMinute()->withoutOverlapping();

Artisan::command('summary-report:rebuild', function (SummaryReports $reports): void {
    $reports->installTriggers();
    $reports->rebuild();
    $this->info('Yearly summary report totals rebuilt.');
})->purpose('Reconcile yearly summary totals with player and betting records');
