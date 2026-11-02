<?php

namespace App\Http\Controllers;

use App\Http\Requests\SaveAgentRequest;
use App\Services\Agents\AgentQueries;
use App\Services\Agents\AgentWorkflow;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class AgentController extends Controller
{
    public function __construct(private AgentQueries $queries, private AgentWorkflow $workflow) {}

    public function index(Request $request): Response
    {
        Gate::authorize('Ending Agents');
        $filters = $this->filters($request);
        $sort = ['name' => 'i.first_name', 'mobile_number' => 'i.mobile_number', 'email_address' => 'i.email_address',
            'username' => 'a.username', 'player_count' => 'player_count', 'verified_count' => 'verified_count',
            'commission' => 'a.commission', 'wallet' => 'a.wallet', 'status' => 'a.is_active', 'created_at' => 'a.created_at'][$filters['sort'] ?? 'name'];

        return Inertia::render('agents/Index', [
            'records' => $this->queries->accounts($filters)->orderBy($sort, $filters['direction'])->orderBy('a.id')->paginate($filters['per_page'])->withQueryString(),
            'filters' => $filters, 'outlets' => DB::table('admin_outlets')->orderBy('outlet_name')->get(['id', 'outlet_name']),
            'ready' => $this->ready(),
        ]);
    }

    public function store(SaveAgentRequest $request): RedirectResponse
    {
        $id = $this->workflow->save($request, null, $request->validated());
        Inertia::flash('success', 'Agent created.');

        return redirect()->route('agents.show', $id);
    }

    public function update(SaveAgentRequest $request, int $agent): RedirectResponse
    {
        $this->workflow->save($request, $agent, $request->validated());
        Inertia::flash('success', 'Agent updated.');

        return back(303);
    }

    public function show(Request $request, int $agent): Response
    {
        Gate::authorize('Ending Agents');
        $request->validate(['search' => ['nullable', 'string', 'max:100'], 'per_page' => ['nullable', 'integer', Rule::in([5, 10, 25, 50, 100])]]);
        $account = $this->queries->accounts([])->where('a.id', $agent)->firstOrFail();
        $players = DB::table('admin_ending_agent_players as link')->where('link.agent_id', $agent)->whereNull('link.detached_at')
            ->leftJoin('players as player', 'player.id', '=', 'link.player_id')
            ->leftJoin('player_profiles as profile', 'profile.player_id', '=', 'link.player_id');
        if ($request->filled('search')) {
            $search = '%'.$request->string('search')->toString().'%';
            $players->where(fn (Builder $q) => $q->where('player.name', 'like', $search)->orWhere('player.mobile_number', 'like', $search));
        }

        return Inertia::render('agents/Account', [
            'account' => $account, 'outlets' => DB::table('admin_outlets')->orderBy('outlet_name')->get(['id', 'outlet_name']),
            'players' => $players->orderBy('link.id')->paginate($request->integer('per_page', 10), [
                'link.id', 'link.player_id', 'link.legacy_player_id', 'link.commission', 'link.created_at',
                'player.name', 'player.mobile_number', 'player.status', 'profile.kyc_status',
            ])->withQueryString(),
            'search' => $request->string('search')->toString(), 'ready' => $this->ready(),
        ]);
    }

    public function candidates(Request $request): JsonResponse
    {
        Gate::authorize('Ending Agents');
        $data = $request->validate(['search' => ['required', 'string', 'min:3', 'max:100']]);
        $search = '%'.$data['search'].'%';
        $records = DB::table('players as p')->where('p.status', 'Active')
            ->where(fn (Builder $q) => $q->where('p.name', 'like', $search)->orWhere('p.mobile_number', 'like', $search))
            ->whereNotExists(fn (Builder $q) => $q->selectRaw('1')->from('admin_ending_agent_players as link')->whereColumn('link.player_id', 'p.id')->whereNull('link.detached_at'))
            ->orderBy('p.name')->orderBy('p.id')->limit(10)->get(['p.id', 'p.name', 'p.mobile_number']);

        return response()->json(['records' => $records])->header('Cache-Control', 'no-store, private');
    }

    public function assign(Request $request, int $agent): RedirectResponse
    {
        Gate::authorize('Ending Agents');
        $request->validate(['player_id' => ['required', 'integer', Rule::exists('players', 'id')]]);
        $this->workflow->assign($request, $agent);
        Inertia::flash('success', 'Player assigned to agent.');

        return back(303);
    }

    public function remove(Request $request, int $agent, int $link): RedirectResponse
    {
        Gate::authorize('Ending Agents');
        $this->workflow->remove($request, $agent, $link);
        Inertia::flash('success', 'Player removed from agent.');

        return back(303);
    }

    public function resetPassword(Request $request, int $agent): RedirectResponse
    {
        Gate::authorize('Ending Agents');
        $request->validate(['password' => ['required', 'string', 'min:8', 'max:255', 'confirmed']]);
        $this->workflow->resetPassword($request, $agent);
        Inertia::flash('success', 'Agent password reset.');

        return back(303);
    }

    public function cashins(Request $request): Response
    {
        Gate::authorize('Ending Agents');
        $filters = $this->filters($request, true);
        $query = $this->queries->cashins($filters);
        $total = (clone $query)->sum('c.amount');
        $sort = ['name' => 'i.first_name', 'amount' => 'c.amount', 'status' => 'c.status', 'created_at' => 'c.created_at', 'evaluated_at' => 'c.evaluated_at'][$filters['sort']];

        return Inertia::render('agents/CashIn', [
            'filters' => $filters, 'records' => $query->orderBy($sort, $filters['direction'])->orderByDesc('c.id')->paginate($filters['per_page'])->withQueryString(),
            'total' => (string) $total, 'ready' => $this->ready(),
            'today' => now('Asia/Manila')->format('Y-m-d'),
            'agents' => $this->queries->accounts([])->orderBy('i.first_name')->get(),
        ]);
    }

    public function fund(Request $request): RedirectResponse
    {
        Gate::authorize('Ending Agents');
        Gate::authorize('Cash In Manual');
        $request->validate([
            'agent_id' => ['required', 'integer', Rule::exists('admin_ending_agents', 'id')],
            'amount' => ['required', 'numeric', 'decimal:0,2', 'gt:0', 'max:99999999.99'],
            'remarks' => ['required', 'string', 'max:300'], 'request_key' => ['required', 'uuid'],
        ]);
        $this->workflow->fund($request);
        Inertia::flash('success', 'Agent wallet funded.');

        return back(303);
    }

    public function evaluate(Request $request, int $cashin, string $action): RedirectResponse
    {
        Gate::authorize('Ending Agents');
        Gate::authorize($action === 'approve' ? 'Cash In Approve' : 'Cash In Dispprove');
        $request->validate([
            'remarks' => [$action === 'disapprove' ? 'required' : 'nullable', 'string', 'max:300'],
        ]);
        $this->workflow->evaluate($request, $cashin, $action);
        Inertia::flash('success', 'Agent cash-in request updated.');

        return back(303);
    }

    /** @return array<string, mixed> */
    private function filters(Request $request, bool $cashin = false): array
    {
        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in($cashin ? ['All', 'Pending', 'Verified', 'Rejected', 'Approved', 'Disapproved'] : ['All', 'Active', 'Inactive'])],
            'type' => ['nullable', Rule::in(['All', 'Regular', 'Special'])],
            'outlet_id' => ['nullable', 'integer', Rule::exists('admin_outlets', 'id')],
            'agent_id' => ['nullable', 'integer', Rule::exists('admin_ending_agents', 'id')],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', ...($request->filled('date_from') ? ['after_or_equal:date_from'] : [])],
            'sort' => ['nullable', Rule::in($cashin ? ['name', 'amount', 'status', 'created_at', 'evaluated_at'] : ['name', 'mobile_number', 'email_address', 'username', 'player_count', 'verified_count', 'commission', 'wallet', 'status', 'created_at'])],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'per_page' => ['nullable', 'integer', Rule::in([5, 10, 25, 50, 100])], 'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $filters = array_filter($data, fn ($value) => $value !== null && $value !== '') + [
            'search' => '', 'status' => 'All', 'type' => 'All', 'per_page' => 5,
            'sort' => $cashin ? 'created_at' : 'name', 'direction' => $cashin ? 'desc' : 'asc',
            'date_from' => $cashin ? now('Asia/Manila')->format('Y-m-d') : '',
            'date_to' => $cashin ? now('Asia/Manila')->format('Y-m-d') : '',
        ];
        foreach (['date_from', 'date_to'] as $column) {
            if (array_key_exists($column, $data)) {
                $filters[$column] = (string) ($data[$column] ?? '');
            }
        }
        foreach (['agent_id', 'outlet_id', 'per_page'] as $column) {
            if (isset($filters[$column])) {
                $filters[$column] = (int) $filters[$column];
            }
        }

        return $filters;
    }

    private function ready(): bool
    {
        return DB::table('admin_agent_imports')->where('source', config('players.legacy_source'))->exists();
    }
}
