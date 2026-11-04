<?php

namespace App\Http\Controllers;

use App\Services\OtsoPanalo\OtsoPromoAnalytics;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

class OtsoPromoAnalyticsController extends Controller
{
    public function index(Request $request, OtsoPromoAnalytics $analytics): Response
    {
        Gate::authorize('Otso Panalo');
        $today = CarbonImmutable::now('Asia/Manila')->toDateString();
        $input = $request->validate([
            'date_from' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:'.$today],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:'.$today],
            'device_type' => ['nullable', Rule::in(['mobile', 'tablet', 'desktop'])],
            'dialog_step' => ['nullable', 'integer', Rule::in([1, 2])],
            'source_route' => ['nullable', 'string', 'max:80', 'regex:/^[A-Za-z][A-Za-z0-9_]{0,79}$/'],
        ]);
        $to = CarbonImmutable::parse($input['date_to'] ?? $today, 'Asia/Manila');
        $from = CarbonImmutable::parse($input['date_from'] ?? $to->subDays(29)->toDateString(), 'Asia/Manila');
        $request->merge(['date_from' => $from->toDateString(), 'date_to' => $to->toDateString()]);
        $request->validate(['date_to' => ['after_or_equal:date_from', 'before_or_equal:'.$from->addDays(92)->toDateString()]]);
        $filters = [
            'date_from' => $from->toDateString(), 'date_to' => $to->toDateString(),
            'device_type' => $input['device_type'] ?? null,
            'dialog_step' => isset($input['dialog_step']) ? (int) $input['dialog_step'] : null,
            'source_route' => $input['source_route'] ?? null,
        ];
        try {
            $report = $analytics->report($filters);
        } catch (QueryException $exception) {
            report($exception);
            $report = null;
        }

        $response = Inertia::render('otso/PromoAnalytics', ['filters' => $filters, 'report' => $report])->toResponse($request);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}
