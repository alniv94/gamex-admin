<?php

namespace App\Http\Controllers;

use App\Services\Players\PlayerCashierQueries;
use App\Services\Players\PlayerCashierWorkflow;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PlayerCashierController extends Controller
{
    public function __construct(private PlayerCashierQueries $queries, private PlayerCashierWorkflow $workflow) {}

    public function index(Request $request, string $kind): Response
    {
        Gate::authorize($kind.'.view');
        $filters = $this->filters($request);

        return Inertia::render('players/Cashier', [
            'kind' => $kind,
            'records' => $this->queries->filtered($kind, $filters)->paginate($filters['per_page'] ?? 5)->withQueryString(),
            'filters' => $filters,
            'today' => now('Asia/Manila')->format('Y-m-d'),
        ]);
    }

    public function export(Request $request, string $kind): StreamedResponse
    {
        Gate::authorize($kind.'.view');
        $query = $this->queries->filtered($kind, $this->filters($request));

        return response()->streamDownload(function () use ($query, $kind): void {
            $stream = fopen('php://output', 'wb');
            if ($stream === false) {
                throw new \RuntimeException('The export stream could not be opened.');
            }
            fwrite($stream, "\xEF\xBB\xBF");
            fputcsv($stream, ['ID', 'PLAYER NAME', 'MOBILE NUMBER', 'BANK', 'ACCOUNT NUMBER', $kind === 'cashin' ? 'REFERENCE NUMBER' : 'ACCOUNT NAME', 'TOKEN', 'TYPE', 'REMARKS', 'STATUS', 'REQUESTED DATE', 'EVALUATED BY', 'EVALUATED DATE'], escape: '');
            foreach ($query->lazy(1000) as $row) {
                $values = [$row->id, $row->player_name, $row->mobile_number, $row->bank, $row->account_number,
                    $kind === 'cashin' ? $row->reference_number : $row->account_name, $row->amount, $row->type,
                    $row->remarks, $row->status, $this->displayDate($row->created_at), $row->evaluated_by, $this->displayDate($row->evaluated_at)];
                fputcsv($stream, array_map(fn ($value) => preg_match('/^\s*[=+@-]/u', (string) $value) ? "'".$value : $value, $values), escape: '');
            }
            fclose($stream);
        }, $kind.'-report-'.now('Asia/Manila')->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function evaluate(Request $request, string $kind, int $cashier, string $action): RedirectResponse
    {
        Gate::authorize($kind.'.view');
        Gate::authorize($kind.'.'.($action === 'reject' ? 'verify' : $action));
        $request->validate([
            'expected' => ['required', Rule::in(['Pending', 'Verified'])],
            'remarks' => [Rule::requiredIf(in_array($action, ['reject', 'disapprove'], true)), 'nullable', 'string', 'max:300'],
        ]);
        $this->workflow->evaluate($request, $kind, $cashier, $action);
        Inertia::flash('success', 'Cash '.($kind === 'cashin' ? 'in' : 'out').' request updated.');

        return back(303);
    }

    public function manual(Request $request, string $kind): RedirectResponse
    {
        Gate::authorize($kind.'.view');
        Gate::authorize($kind.'.manual');
        $request->validate([
            'player_id' => ['required', 'integer', Rule::exists('players', 'id')->where('status', 'Active')],
            'amount' => ['required', 'numeric', 'decimal:0,2', 'gt:0', 'max:99999999.99'],
            'remarks' => ['required', 'string', 'max:300'],
            'approval_password' => ['required', 'string', 'max:255'],
            'request_key' => ['required', 'uuid'],
        ]);
        $this->workflow->manual($request, $kind);
        Inertia::flash('success', 'Manual cash '.($kind === 'cashin' ? 'in' : 'out').' completed.');

        return back(303);
    }

    public function players(Request $request, string $kind): JsonResponse
    {
        Gate::authorize($kind.'.view');
        Gate::authorize($kind.'.manual');
        $request->validate(['search' => ['required', 'string', 'min:3', 'max:100']]);
        $search = $request->string('search')->toString();

        return response()->json(DB::table('players as p')->join('player_profiles as profile', 'profile.player_id', '=', 'p.id')
            ->join('player_wallet as w', 'w.player_id', '=', 'p.id')
            ->where('p.status', 'Active')->where('profile.kyc_status', 'Verified')->where('p.'.$kind, 'Enabled')
            ->where(fn ($query) => $query->where('p.name', 'like', '%'.$search.'%')->orWhere('p.mobile_number', 'like', '%'.$search.'%'))
            ->orderBy('p.name')->limit(10)->get(['p.id', 'p.name', 'p.mobile_number', 'w.actual_amount as wallet']))
            ->header('Cache-Control', 'no-store, private');
    }

    /** @return array<string, mixed> */
    private function filters(Request $request): array
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'filter' => ['nullable', Rule::in(['All', 'Pending', 'Verified', 'Rejected', 'Approved', 'Disapproved'])],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', ...($request->filled('date_from') ? ['after_or_equal:date_from'] : [])],
            'sort' => ['nullable', Rule::in(['player_name', 'mobile_number', 'bank', 'wallet', 'amount', 'type', 'remarks', 'status', 'created_at', 'evaluated_at'])],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'per_page' => ['nullable', 'integer', Rule::in([5, 10, 15, 20, 25, 50, 100])],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        foreach (['search' => '', 'filter' => 'Pending', 'date_from' => '', 'date_to' => '', 'sort' => 'created_at', 'direction' => 'desc'] as $key => $default) {
            $filters[$key] ??= $default;
        }

        return $filters;
    }

    private function displayDate(?string $date): string
    {
        return $date ? CarbonImmutable::parse($date, 'UTC')->setTimezone('Asia/Manila')->format('M d, Y h:i A') : '';
    }
}
