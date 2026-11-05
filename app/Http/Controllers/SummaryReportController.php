<?php

namespace App\Http\Controllers;

use App\Services\SummaryReports;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class SummaryReportController extends Controller
{
    public function index(SummaryReports $reports): Response
    {
        Gate::authorize('Players Summary');

        return Inertia::render('SummaryReport', [
            'records' => fn (): array => $reports->rows(),
        ]);
    }
}
