<?php

namespace App\Http\Controllers;

use App\Services\Players\PlayerReportQueries;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PlayerReportController extends Controller
{
    private const TITLES = [
        'registered' => 'Registered Player List',
        'new' => 'Newly Registered Players',
        'active' => 'Active Players Summary',
    ];

    public function __construct(private PlayerReportQueries $queries) {}

    public function index(Request $request, string $report): Response
    {
        Gate::authorize('Player List');
        $filters = $this->filters($request, $report);
        $query = $this->queries->filtered($report, $filters);

        return Inertia::render('players/Report', [
            'report' => $report,
            'title' => self::TITLES[$report],
            'players' => $query->paginate($filters['per_page'])->withQueryString(),
            'filters' => $filters,
            'today' => now('Asia/Manila')->format('Y-m-d'),
            'luckyFourReady' => $report !== 'active' || DB::table('admin_player_report_imports')
                ->where('source', config('players.legacy_source'))->exists(),
        ]);
    }

    public function export(Request $request, string $report): StreamedResponse
    {
        Gate::authorize('Player List');
        $filters = $this->filters($request, $report);
        $query = $this->queries->filtered($report, $filters);
        $scope = $request->validate(['scope' => ['nullable', Rule::in(['all', 'page'])]])['scope'] ?? 'all';
        $columns = ['id' => 'Player ID', 'name' => 'Name', 'mobile_number' => 'Mobile Number'];
        $columns += $report === 'active'
            ? ['city_municipality' => 'City', 'age' => 'Age', 'gender' => 'Gender', 'total_bet_count' => 'Total Bet Count',
                'ending_amount' => 'Happy Ending Amount', 'lucky_four_amount' => 'Lucky Four Amount',
                'total_bets' => 'Total Bet Amount', 'total_payouts' => 'Total Payouts', 'net_amount' => 'Net Amount']
            : ['email_address' => 'Email', 'account_verified' => 'Verification Status', 'active_status' => 'Account Status',
                'created_at' => 'Registered Date', 'city_municipality' => 'City'];

        return response()->streamDownload(function () use ($query, $filters, $scope, $columns): void {
            $stream = fopen('php://output', 'wb');
            if ($stream === false) {
                throw new \RuntimeException('The export stream could not be opened.');
            }
            fwrite($stream, "\xEF\xBB\xBF");
            fputcsv($stream, array_values($columns), escape: '');
            $rows = $scope === 'page' ? $query->forPage($filters['page'], $filters['per_page'])->get() : $query->lazy(1000);
            foreach ($rows as $row) {
                $values = [];
                foreach ($columns as $column => $label) {
                    $value = $row->{$column};
                    if ($column === 'created_at' && $value) {
                        $value = CarbonImmutable::parse($value, 'UTC')->setTimezone('Asia/Manila')->format('Y-m-d H:i:s');
                    }
                    $values[] = preg_match('/^\s*[=+@-]/u', (string) $value) ? "'".$value : $value;
                }
                fputcsv($stream, $values, escape: '');
            }
            fclose($stream);
        }, 'players-'.$report.'-'.$scope.'-'.now('Asia/Manila')->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** @return array<string, mixed> */
    private function filters(Request $request, string $report): array
    {
        $today = now('Asia/Manila')->format('Y-m-d');
        $request->merge([
            'date_from' => $request->input('date_from', $report === 'registered' ? null : $today),
            'date_to' => $request->input('date_to', $report === 'registered' ? null : $today),
        ]);
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'date_from' => [$report === 'registered' ? 'nullable' : 'required', 'date_format:Y-m-d'],
            'date_to' => [$report === 'registered' ? 'nullable' : 'required', 'date_format:Y-m-d',
                ...($request->filled('date_from') ? ['after_or_equal:date_from'] : [])],
            'status' => ['nullable', Rule::in(['All', 'Active', 'Inactive'])],
            'account_verified' => ['nullable', Rule::in(['All', 'Verified', 'Pending', 'Files Submitted', 'Rejected'])],
            'sort' => ['nullable', Rule::in(PlayerReportQueries::sortColumns($report))],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'per_page' => ['nullable', 'integer', Rule::in([5, 10, 25, 50, 100])],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        foreach (['search' => '', 'status' => 'All', 'account_verified' => 'All',
            'sort' => $report === 'active' ? 'total_bets' : 'created_at', 'direction' => 'desc', 'per_page' => 10, 'page' => 1] as $key => $default) {
            $filters[$key] = $filters[$key] ?? $default;
        }

        return $filters;
    }
}
