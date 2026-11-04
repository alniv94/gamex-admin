<?php

namespace App\Http\Controllers;

use App\Http\Requests\SendPlayerSmsRequest;
use App\Services\Players\PlayerSms;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class SettingsSmsController extends Controller
{
    public function index(Request $request, PlayerSms $sms): Response
    {
        Gate::authorize('SMS');
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'sort' => ['sometimes', Rule::in(['player_name'])],
            'direction' => ['sometimes', Rule::in(['asc', 'desc'])],
            'per_page' => ['sometimes', 'integer', Rule::in([10, 25, 50, 100, 500])],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);
        $query = $sms->eligiblePlayers();
        $countKey = 'admin-sms:recipient-count:v1:'.hash('sha256', (string) config('players.legacy_source'));
        $totalRecipients = Cache::remember($countKey, 30, fn (): int => (clone $query)->count());
        $search = $filters['search'] ?? '';
        $query->select(['p.id', 'p.name as player_name', 'p.mobile_number'])
            ->when($search !== '', fn (Builder $query) => $query
                ->where(fn (Builder $query) => $query->where('p.name', 'like', '%'.$search.'%')
                    ->orWhere('p.mobile_number', 'like', '%'.$search.'%')))
            ->orderBy('p.name', $filters['direction'] ?? 'asc')->orderBy('p.id', $filters['direction'] ?? 'asc');
        $filteredTotal = $search === '' ? $totalRecipients
            : Cache::remember($countKey.':'.hash('sha256', $search), 30, fn (): int => (clone $query)->count());
        $players = $query->paginate($filters['per_page'] ?? 10, total: $filteredTotal)->withQueryString();

        return Inertia::render('settings/Sms', [
            'players' => $players, 'totalRecipients' => $totalRecipients, 'filters' => $filters,
            'submissionId' => (string) Str::uuid(),
        ]);
    }

    public function store(SendPlayerSmsRequest $request, PlayerSms $sms): RedirectResponse
    {
        $count = $sms->send(
            (int) $request->user()->id, $request->validated('request_id'), $request->validated('message'),
            $request->boolean('blast'), $request->validated('player_ids', []),
        );
        Inertia::flash('success', 'SMS submitted for '.number_format($count).' recipient'.($count === 1 ? '' : 's').'.');

        return back(303);
    }
}
