<?php

namespace App\Http\Controllers;

use App\Http\Requests\SaveInvestorRequest;
use App\Services\Investors\InvestorQueries;
use App\Services\Investors\InvestorWorkflow;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class InvestorController extends Controller
{
    public const SECTIONS = [
        'list' => 'Investor List', 'payouts' => 'Payouts Settlement', 'commissions' => 'Commission Settlement',
        'master-agents' => 'Master Agent List', 'special-commissions' => 'Special Commission Settlement', 'referral-links' => 'Referral Links',
    ];

    public function __construct(private InvestorQueries $queries, private InvestorWorkflow $workflow) {}

    public function index(Request $request, string $section): Response
    {
        $this->authorizeSection($section);
        $filters = $this->filters($request, $section);

        return Inertia::render('investors/Index', [
            'section' => $section, 'title' => self::SECTIONS[$section], 'filters' => $filters,
            'records' => $this->records($section, $filters),
            'outlets' => DB::table('admin_outlets')->orderBy('outlet_name')->get(['id', 'outlet_name']),
            'ready' => DB::table('admin_investor_imports')->where('source', config('players.legacy_source'))->exists(),
        ]);
    }

    public function show(Request $request, int $investor): Response
    {
        Gate::authorize('Investors');
        $account = $this->queries->investors([])->where('i.id', $investor)->first();
        abort_unless($account !== null, 404);
        $children = $this->queries->investors([])->join('admin_investor_sub_agents as e', 'e.investor_agent_id', '=', 'i.id')
            ->where('e.investor_id', $investor)->orderBy('i.id')->get();
        $players = DB::table('admin_investor_sub_agent_players as p')->leftJoin('players as u', 'u.id', '=', 'p.player_id')
            ->where('p.investor_agent_id', $investor)->orderBy('p.id')
            ->paginate(10, ['p.id', 'p.player_id', 'p.legacy_player_id', 'p.investor_sub_type_id', 'p.commission', 'u.name'])->withQueryString();

        return Inertia::render('investors/Account', [
            'account' => $account, 'children' => $children, 'players' => $players,
            'subAccounts' => DB::table('admin_investor_sub_users')->where('investor_id', $investor)->orderBy('id')
                ->get(['id', 'first_name', 'last_name', 'mobile_number', 'status', 'created_at']),
            'outlets' => DB::table('admin_outlets')->orderBy('outlet_name')->get(['id', 'outlet_name']),
            'settlements' => DB::table('admin_investor_settled_commissions')->where('investor_id', $investor)
                ->selectRaw("id, amount, mode_of_payment, reference, remarks, processed_at, 'Commission' as kind")
                ->unionAll(DB::table('admin_investor_settled_payouts')->where('investor_id', $investor)
                    ->selectRaw("id, amount, mode_of_payment, reference, remarks, processed_at, 'Payout' as kind"))
                ->orderByDesc('processed_at')->paginate(10, ['*'], 'settlements_page')->withQueryString(),
        ]);
    }

    public function store(SaveInvestorRequest $request): RedirectResponse
    {
        $id = $this->workflow->save(null, $request->validated(), $request->user()->id);
        Inertia::flash('success', 'Investor created.');

        return redirect()->route('investors.accounts.show', $id);
    }

    public function update(SaveInvestorRequest $request, int $investor): RedirectResponse
    {
        $this->workflow->save($investor, $request->validated(), $request->user()->id);
        Inertia::flash('success', 'Investor updated.');

        return back();
    }

    public function subAccount(Request $request, int $investor, ?int $account = null): RedirectResponse
    {
        Gate::authorize('Investors');
        $data = $request->validate([
            'first_name' => ['required', 'string', 'min:2', 'max:45'],
            'last_name' => ['required', 'string', 'min:2', 'max:45'],
            'mobile_number' => ['required', 'regex:/^9[0-9]{9}$/', Rule::unique('admin_investor_sub_users', 'mobile_number')->ignore($account)],
            'password' => [$account ? 'nullable' : 'required', 'string', 'min:8', 'max:255'],
            'status' => ['required', Rule::in(['Active', 'Inactive'])],
        ]);
        $this->workflow->subAccount($investor, $account, $data, $request->user()->id);
        Inertia::flash('success', 'Sub-account saved.');

        return back();
    }

    public function subAccounts(int $investor): JsonResponse
    {
        Gate::authorize('Investors');
        abort_unless(DB::table('admin_investors')->where('id', $investor)->exists(), 404);

        return response()->json(['records' => DB::table('admin_investor_sub_users')->where('investor_id', $investor)->orderBy('id')
            ->get(['id', 'first_name', 'last_name', 'mobile_number', 'status', 'created_at'])]);
    }

    public function hierarchy(int $investor, string $kind): JsonResponse
    {
        Gate::authorize('Investors');
        abort_unless(DB::table('admin_investors')->where('id', $investor)->exists(), 404);
        $records = $kind === 'agents'
            ? $this->queries->investors([])->join('admin_investor_sub_agents as e', 'e.investor_agent_id', '=', 'i.id')
                ->where('e.investor_id', $investor)->orderBy('i.id')->get()
            : DB::table('admin_investor_sub_agent_players as p')->leftJoin('players as u', 'u.id', '=', 'p.player_id')
                ->where('p.investor_agent_id', $investor)->where('p.investor_sub_type_id', 2)->orderBy('p.id')
                ->get(['p.id', 'p.player_id', 'p.legacy_player_id', 'p.commission', 'u.name', 'u.mobile_number', 'u.status']);

        return response()->json(['records' => $records]);
    }

    public function refreshReferrals(Request $request, int $investor): RedirectResponse
    {
        Gate::authorize('Investors');
        $this->workflow->refreshReferrals($investor, $request->user()->id);
        Inertia::flash('success', 'Referral links updated.');

        return back();
    }

    public function settlement(Request $request, string $kind, int $investor): Response
    {
        Gate::authorize('Investors');
        $filters = $this->filters($request, $kind);
        $account = $this->queries->investors([])->where('i.id', $investor)->where('i.investor_type_id', 1)->first();
        abort_unless($account !== null, 404);
        $query = $this->queries->pending($kind, $investor, $filters)->select('*')
            ->selectRaw($kind === 'payouts' ? "'Payout' as ledger" : "'Base' as ledger");
        if ($kind === 'commissions') {
            $columns = ['id', 'investor_id', 'player_id', 'legacy_player_id', 'bet_id', 'commission_percent', 'commission_amount', 'bet_created_at'];
            $query = $this->queries->pending($kind, $investor, $filters)->select($columns)->selectRaw("'Base' as ledger")
                ->unionAll($this->queries->pending($kind, $investor, $filters, 'referral')->select($columns)->selectRaw("'Referral' as ledger"));
        }
        $total = $kind === 'payouts'
            ? $this->queries->pending($kind, $investor, $filters)->sum('amount')
            : $this->queries->pending($kind, $investor, $filters)->sum('commission_amount') + $this->queries->pending($kind, $investor, $filters, 'referral')->sum('commission_amount');
        if ($kind === 'payouts') {
            $query->select(['id', 'investor_id', 'player_id', 'legacy_player_id', 'amount', 'created_at'])->selectRaw("'Payout' as ledger");
        }

        return Inertia::render('investors/Settlement', [
            'kind' => $kind, 'account' => $account, 'filters' => $filters, 'total' => (string) $total,
            'items' => $query->orderBy('id')->paginate($filters['per_page'])->withQueryString(),
        ]);
    }

    public function settle(Request $request, string $kind, int $investor): RedirectResponse
    {
        Gate::authorize('Investors');
        $filters = $this->filters($request, $kind);
        $reference = $this->workflow->settle($kind, $investor, $filters, $this->payment($request), $request->user()->id);
        Inertia::flash('success', 'Settlement '.$reference.' recorded.');

        return redirect()->route('investors.index', ['section' => $kind, ...$filters]);
    }

    public function specialDetails(Request $request, int $settlement): Response
    {
        Gate::authorize('Special Commission Settlement');
        $row = DB::table('admin_investor_special_settlement')->where('id', $settlement)->first(['id', 'investor_id', 'amount', 'status', 'mode_of_payment', 'reference', 'remarks']);
        abort_unless($row !== null, 404);

        return Inertia::render('investors/Settlement', [
            'kind' => 'special', 'special' => $row, 'filters' => [], 'total' => (string) $row->amount,
            'account' => $this->queries->investors([])->where('i.id', $row->investor_id)->first(),
            'items' => DB::table('admin_investor_commissions')->where('special_settlement_id', $settlement)->orderBy('id')
                ->paginate(25, ['id', 'investor_id', 'player_id', 'legacy_player_id', 'bet_id', 'commission_percent', 'commission_amount', 'bet_created_at'])->withQueryString(),
        ]);
    }

    public function specialAction(Request $request, int $settlement, string $action): RedirectResponse
    {
        Gate::authorize('Special Commission Settlement '.($action === 'verify' ? 'Verify' : 'Approve'));
        $this->workflow->special($settlement, $action, $action === 'approve' ? $this->payment($request) : [], $request->user()->id);
        Inertia::flash('success', 'Special commission settlement '.$action.' completed.');

        return back();
    }

    public function export(Request $request, string $section): StreamedResponse
    {
        $this->authorizeSection($section);
        $rows = $this->records($section, $this->filters($request, $section), false);

        return response()->streamDownload(function () use ($rows): void {
            $stream = fopen('php://output', 'wb');
            if ($stream === false) {
                throw new \RuntimeException('The export stream could not be opened.');
            }
            fwrite($stream, "\xEF\xBB\xBF");
            $first = true;
            foreach ($rows as $row) {
                $data = (array) $row;
                if ($first) {
                    fputcsv($stream, array_keys($data), escape: '');
                    $first = false;
                }
                fputcsv($stream, array_map(fn ($value) => preg_match('/^\s*[=+@-]/u', (string) $value) ? "'".$value : $value, $data), escape: '');
            }
            fclose($stream);
        }, 'investors-'.$section.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function authorizeSection(string $section): void
    {
        Gate::authorize($section === 'special-commissions' ? 'Special Commission Settlement' : 'Investors');
    }

    /** @return array<string, mixed> */
    private function filters(Request $request, string $section): array
    {
        if ($section === 'commissions') {
            $request->merge([
                'date_from' => $request->input('date_from', now('Asia/Manila')->startOfMonth()->format('Y-m-d')),
                'date_to' => $request->input('date_to', now('Asia/Manila')->endOfMonth()->format('Y-m-d')),
            ]);
        }
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['All', 'Active', 'Inactive', 'Requested', 'Verified', 'Approved'])],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', ...($request->filled('date_from') ? ['after_or_equal:date_from'] : [])],
            'link_type' => ['nullable', Rule::in(['Old', 'New'])],
            'sort' => ['nullable', Rule::in(['id', 'name', 'territory', 'mobile_number', 'base_commission', 'commission', 'sub_commission', 'category', 'wallet', 'link_type', 'created_at', 'amount', 'min_date', 'total_accounts', 'sales_manager_count', 'regular_player_count', 'status', 'bet_count', 'bet_amount', 'link_for', 'url'])],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'per_page' => ['nullable', 'integer', Rule::in([5, 10, 25, 50, 100])],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        foreach (['sort', 'direction', 'per_page', 'page', 'link_type'] as $key) {
            if (($filters[$key] ?? null) === null) {
                unset($filters[$key]);
            }
        }

        return $filters + ['search' => '', 'status' => 'All', 'date_from' => '', 'date_to' => '', 'link_type' => 'New',
            'sort' => $section === 'list' ? 'created_at' : ($section === 'commissions' ? 'amount' : 'id'),
            'direction' => $section === 'list' ? 'desc' : 'asc',
            'per_page' => in_array($section, ['list', 'referral-links'], true) ? 10 : 5, 'page' => 1];
    }

    /** @return array<string, mixed> */
    private function payment(Request $request): array
    {
        return $request->validate([
            'mode_of_payment' => ['required', 'string', 'max:255'],
            'reference' => ['required', 'string', 'max:255'],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ]);
    }

    /** @param array<string, mixed> $filters
     * @return LengthAwarePaginator<int, mixed>|Collection<int, mixed>
     */
    private function records(string $section, array $filters, bool $paginate = true): LengthAwarePaginator|Collection
    {
        if ($section === 'special-commissions') {
            $query = $this->queries->special($filters);
            $sort = ['name' => 'i.first_name', 'amount' => 's.amount', 'status' => 's.status'][$filters['sort']] ?? 's.id';
            $query->reorder()->orderBy($sort, $filters['direction'])->orderBy('s.id');

            return $paginate ? $query->paginate($filters['per_page'])->withQueryString() : $query->get();
        }
        if ($section === 'referral-links') {
            $rows = collect();
            $old = $filters['link_type'] === 'Old';
            $accountFilters = $filters;
            unset($accountFilters['search']);
            $accounts = $old ? $this->queries->investors($accountFilters)->orderBy('i.id')->get() : $this->queries->referrals($accountFilters)->get();
            foreach ($accounts as $account) {
                $types = $old ? match ((int) $account->investor_type_id) {
                    1 => ['SM', 'RP'], 2 => ['SS', 'SR', 'RP'], default => ['SR', 'RP']
                } : [$account->referral_type];
                foreach ($types as $type) {
                    $id = $old ? $account->id : $account->referral_id;
                    $role = $old ? match ((int) $account->investor_type_id) {
                        1 => 'MA', 2 => 'SM', default => 'SS'
                    } : $type;
                    $rows->push((object) [
                        'id' => $id.'-'.$type, 'investor_id' => $account->id, 'first_name' => $account->first_name, 'last_name' => $account->last_name,
                        'name' => trim($account->first_name.' '.$account->last_name).' ('.$role.')',
                        'territory' => $account->territory, 'referral_type' => $type,
                        'link_for' => match ($type) {
                            'SM' => 'Sales Manager', 'SS' => 'Sales Supervisor', 'SR' => 'Sales Representative', 'RP' => 'Regular Player', default => 'N/A'
                        },
                        'url' => $this->queries->referralUrl($type, $account->territory, (int) $id, $old),
                    ]);
                }
            }

            $search = trim((string) ($filters['search'] ?? ''));
            if ($search !== '') {
                $rows = $rows->filter(fn ($row) => mb_stripos($row->name.' '.$row->link_for.' '.$row->url, $search) !== false);
            }
            $rows = $this->sortRecords($rows, $filters);

            return $paginate ? (new LengthAwarePaginator($rows->forPage($filters['page'], $filters['per_page'])->values(), $rows->count(), $filters['per_page'], $filters['page'], ['path' => request()->url()]))->withQueryString() : $rows;
        }
        $query = $this->queries->investors($filters)->where('i.investor_type_id', 1);
        if ($section === 'payouts') {
            $query->joinSub($this->queries->payouts($filters), 'p', fn ($join) => $join->on('p.investor_id', '=', 'i.id'))
                ->addSelect(['p.amount', 'p.min_date', 'p.max_date']);
        }
        if ($section === 'list') {
            $this->queries->dates($query, 'i.created_at', $filters);
        }
        $records = $query->orderBy('i.id')->get();
        $decorate = function ($row) use ($section, $filters) {
            if ($section === 'commissions') {
                $base = $this->queries->pending($section, $row->id, $filters)->sum('commission_amount');
                $referral = $this->queries->pending($section, $row->id, $filters, 'referral')->sum('commission_amount');
                $row->amount = $this->workflow->amount($this->workflow->cents($base) + $this->workflow->cents($referral));
                $bets = $this->queries->betSummary($row->id, $filters);
                $row->bet_count = $bets['bet_count'];
                $row->bet_amount = $bets['bet_amount'];
            }
            if ($section === 'master-agents') {
                $ids = $this->queries->hierarchy($row->id);
                $row->sales_manager_count = DB::table('admin_investor_sub_agents')->where('investor_id', $row->id)->count();
                $row->regular_player_count = DB::table('admin_investor_sub_agent_players')->where('investor_agent_id', $row->id)->where('investor_sub_type_id', 2)->count();
                $row->total_accounts = count($ids) - 1 + DB::table('admin_investor_sub_agent_players')
                    ->whereIn('investor_agent_id', $ids)->whereIn('investor_sub_type_id', [1, 2])
                    ->where(fn ($players) => $players->where('investor_agent_id', '!=', $row->id)->orWhere('investor_sub_type_id', 2))->count();
            }

            return $row;
        };

        $rows = $this->sortRecords($records->map($decorate), $filters);

        return $paginate ? (new LengthAwarePaginator($rows->forPage($filters['page'], $filters['per_page'])->values(), $rows->count(), $filters['per_page'], $filters['page'], ['path' => request()->url()]))->withQueryString() : $rows;
    }

    /** @param Collection<int, \stdClass> $records
     * @param  array<string, mixed>  $filters
     * @return Collection<int, \stdClass>
     */
    private function sortRecords(Collection $records, array $filters): Collection
    {
        return $records->sort(function ($a, $b) use ($filters): int {
            $key = $filters['sort'];
            $left = $key === 'name' ? ($a->name ?? trim($a->first_name.' '.$a->last_name)) : ($a->{$key} ?? null);
            $right = $key === 'name' ? ($b->name ?? trim($b->first_name.' '.$b->last_name)) : ($b->{$key} ?? null);
            $numeric = in_array($key, ['id', 'base_commission', 'commission', 'sub_commission', 'wallet', 'amount', 'total_accounts', 'sales_manager_count', 'regular_player_count', 'bet_count', 'bet_amount'], true);
            $comparison = $numeric ? (float) $left <=> (float) $right : strcmp(mb_strtolower((string) $left), mb_strtolower((string) $right));
            $comparison = $comparison ?: (isset($a->referral_type) ? 0 : $a->id <=> $b->id);

            return $filters['direction'] === 'desc' ? -$comparison : $comparison;
        })->values();

    }
}
