<?php

namespace App\Http\Controllers;

use App\Http\Requests\CreateEndingCardsRequest;
use App\Http\Requests\SaveEndingGameRequest;
use App\Http\Requests\SaveEndingResultRequest;
use App\Models\AdminEndingImport;
use App\Models\AdminEndingLegacyLog;
use App\Models\AdminEndingLog;
use App\Models\EndingBet;
use App\Models\EndingBetAmount;
use App\Models\EndingBetAmountPrize;
use App\Models\EndingCard;
use App\Models\EndingConfig;
use App\Models\EndingGame;
use App\Models\EndingGameResult;
use App\Models\User;
use App\Services\Ending\EndingAccess;
use App\Services\Ending\EndingQueries;
use App\Services\Ending\EndingWorkflow;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class EndingGameController extends Controller
{
    public function __construct(private EndingAccess $access, private EndingQueries $queries, private EndingWorkflow $workflow) {}

    public function index(Request $request): Response
    {
        $this->permit($request, 17);
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'], 'league_id' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::in(['scheduled', 'ongoing', 'finished'])],
            'date_from' => ['nullable', 'date_format:Y-m-d'], 'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'per_page' => ['sometimes', 'integer', Rule::in([5, 10, 25, 50, 100])],
            'sort' => ['nullable', Rule::in(['schedule', 'enabled_betting_at', 'created_at'])],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
        ]);
        $games = $this->queries->games();
        if ($request->filled('search')) {
            $search = '%'.$request->string('search').'%';
            $games->where(fn (Builder $query) => $query
                ->whereHas('teamA', fn (Builder $teams) => $teams->where('team_name', 'like', $search))
                ->orWhereHas('teamB', fn (Builder $teams) => $teams->where('team_name', 'like', $search))
                ->orWhere('ending_games.id', $request->input('search')));
        }
        if ($request->filled('league_id')) {
            $games->whereHas('teamA', fn (Builder $teams) => $teams->where('league_id', $request->integer('league_id')));
        }
        match ($request->input('status')) {
            'finished' => $games->where('game_status_id', 5),
            'scheduled' => $games->whereNull('game_status_id'),
            'ongoing' => $games->whereBetween('game_status_id', [1, 4]),
            default => null,
        };
        foreach (['date_from' => '>=', 'date_to' => '<='] as $field => $operator) {
            if ($request->filled($field)) {
                $date = CarbonImmutable::parse($request->string($field)->toString(), 'Asia/Manila');
                $games->where('schedule', $operator, ($field === 'date_to' ? $date->endOfDay() : $date->startOfDay())->utc());
            }
        }

        return Inertia::render('ending/Games', $this->queries->lookups() + [
            'games' => $games->orderBy($request->input('sort') ?: 'schedule', $request->input('direction') ?: 'desc')->orderByDesc('id')->paginate($request->integer('per_page', 25))->withQueryString()
                ->through(fn (EndingGame $game) => $game->summary()),
            'filters' => $filters,
            'migrationReady' => AdminEndingImport::query()->where('source', config('ending.legacy_source'))->exists(),
        ]);
    }

    public function show(Request $request, EndingGame $game): Response
    {
        $this->permit($request, 17);
        $request->validate(['card_id' => ['nullable', 'integer', 'min:1'], 'bet_amount_id' => ['nullable', 'integer', 'min:1']]);
        if ($request->filled('bet_amount_id')) {
            $game->betAmounts()->findOrFail($request->integer('bet_amount_id'));
        }
        $cardThreshold = max(0, (int) (EndingConfig::query()->where('config_name', 'Card Allow Open')->value('config_value') ?? 50));

        return Inertia::render('ending/Game', $this->queries->lookups() + [
            'game' => fn () => $game->loadMissing(['teamA.league', 'teamB', 'gameStatus'])->summary(),
            'amounts' => fn () => $game->betAmounts()->withCount([
                'cards', 'cards as disabled_cards_count' => fn (Builder $cards) => $cards->where('betting', 'Disabled'),
                'cards as generated_cards_count' => fn (Builder $cards) => $cards->where('generated_file', 'Yes'),
                'cards as exceeded_cards_count' => fn (Builder $cards) => $cards->has('bets', '>', $cardThreshold),
            ])->orderBy('amount')->orderBy('id')->get(['id', 'game_id', 'amount', 'bet_type_id'])
                ->map(fn ($amount) => ['id' => $amount->id, 'amount' => $amount->amount, 'bet_type_id' => $amount->bet_type_id, 'cards' => $amount->cards_count,
                    'disabled_cards' => $amount->disabled_cards_count, 'generated_cards' => $amount->generated_cards_count, 'exceeded_cards' => $amount->exceeded_cards_count]),
            'cardThreshold' => $cardThreshold,
            'cardAmountFilter' => $request->filled('bet_amount_id') ? $request->integer('bet_amount_id') : null,
            'prizes' => fn () => EndingBetAmountPrize::query()->whereHas('betAmount', fn (Builder $amounts) => $amounts->where('game_id', $game->id))
                ->orderBy('id')->get(['id', 'bet_amount_id', 'quarter_id', 'is_cash', 'cash_prize', 'other_prize']),
            'cards' => fn () => $game->cards()->with('betAmount:id,amount,bet_type_id')->withCount('bets')->orderBy('ending_cards.id')
                ->when($request->filled('bet_amount_id'), fn (Builder $cards) => $cards->where('bet_amount_id', $request->integer('bet_amount_id')))
                ->paginate(20, ['ending_cards.*'], 'cards_page')->withQueryString()->through(fn (EndingCard $card) => $card->summary()),
            'results' => fn () => $game->results()->with('slot:id,slot')->orderBy('quarter_id')->orderBy('id')->get()
                ->map(fn (EndingGameResult $result) => [
                    'id' => $result->id, 'quarter_id' => $result->quarter_id, 'team_a_score' => $result->team_a_score,
                    'team_b_score' => $result->team_b_score, 'created_at' => $result->created_at?->toIso8601String(),
                    'combination' => $result->slot?->slot,
                ]),
            'winners' => fn () => $this->access->allows($request->user(), 34)
                ? $this->queries->winners($game->id)->orderBy('r.quarter_id')->orderBy('b.id')->paginate(25, ['*'], 'winners_page')->withQueryString()
                : null,
            'logs' => fn () => $game->logs()->with('actor:id,name')->orderByDesc('created_at')->orderByDesc('id')
                ->paginate(20, ['*'], 'logs_page')->withQueryString()->through(fn (AdminEndingLog $log) => $this->logData($log)),
            'legacyLogs' => fn () => $game->legacyLogs()->with('actor:id,name')->orderByDesc('created_at')->orderByDesc('id')
                ->paginate(20, ['*'], 'legacy_page')->withQueryString()->through(fn (AdminEndingLegacyLog $log) => $this->logData($log)),
            'cardBets' => Inertia::optional(fn () => $this->cardBets($game->cards()->findOrFail($request->integer('card_id')))),
        ]);
    }

    public function cardIndex(Request $request, EndingGame $game): Response
    {
        $this->permit($request, 17);
        $filters = $request->validate([
            'bet_amount_id' => ['nullable', 'integer', 'min:1'],
            'card_id' => ['nullable', 'integer', 'min:1'],
            'search' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', Rule::in([5, 10, 25, 50, 100])],
        ]);
        $amount = $request->filled('bet_amount_id') ? $game->betAmounts()->findOrFail($request->integer('bet_amount_id')) : null;
        $cards = fn () => $game->cards()->when($amount, fn (Builder $cards) => $cards->where('bet_amount_id', $amount->id));
        $search = ltrim($request->string('search')->trim()->toString(), '#0');

        return Inertia::render('ending/Cards', [
            'game' => fn () => $game->loadMissing(['teamA.league', 'teamB', 'gameStatus'])->summary(),
            'amount' => $amount ? Arr::only($amount->attributesToArray(), ['id', 'amount', 'bet_type_id']) : null,
            'filters' => Arr::only($filters, ['search', 'per_page']),
            'cards' => fn () => $cards()->with('betAmount:id,amount,bet_type_id')->withCount('bets')
                ->when($request->filled('search'), fn (Builder $cards) => $cards->where('ending_cards.id', 'like', '%'.($search ?: '0').'%'))
                ->orderBy('ending_cards.id')->paginate($request->integer('per_page', 5), ['ending_cards.*'])
                ->withQueryString()->through(fn (EndingCard $card) => $card->summary()),
            'cardBets' => Inertia::optional(fn () => $this->cardBets($cards()->findOrFail($request->integer('card_id')))),
        ]);
    }

    public function history(Request $request): Response
    {
        $this->permit($request, 17);
        $request->validate(['action' => ['nullable', 'string', 'max:100'], 'game_id' => ['nullable', 'integer', 'min:1']]);
        $logs = AdminEndingLegacyLog::query()->with('actor:id,name');
        if ($request->filled('action')) {
            $logs->where('action', $request->string('action')->toString());
        }
        if ($request->filled('game_id')) {
            $logs->where('game_id', $request->integer('game_id'));
        }

        return Inertia::render('ending/History', [
            'logs' => $logs->orderByDesc('created_at')->orderByDesc('id')->paginate(30)->withQueryString()
                ->through(fn (AdminEndingLegacyLog $log) => $this->logData($log)),
            'filters' => $request->only('action', 'game_id'),
        ]);
    }

    public function store(SaveEndingGameRequest $request): RedirectResponse
    {
        $gameId = $this->workflow->create($request);

        return $this->success($gameId, 'Game created.');
    }

    public function update(SaveEndingGameRequest $request, EndingGame $game): RedirectResponse
    {
        $this->workflow->update($request, $game->id);

        return $this->success($game->id, 'Game updated.');
    }

    public function betting(Request $request, EndingGame $game): RedirectResponse
    {
        $this->permit($request, 10);
        $request->validate(['enabled' => ['required', 'boolean']]);
        $this->workflow->betting($request, $game->id);

        return $this->success($game->id, 'Betting updated.');
    }

    public function cards(CreateEndingCardsRequest $request, EndingGame $game): RedirectResponse
    {
        $this->workflow->addCards($request, $game->id);

        return $this->success($game->id, 'Cards created.');
    }

    public function cardBetting(Request $request, EndingGame $game, EndingCard $card): RedirectResponse
    {
        $this->permit($request, 12);
        $request->validate(['enabled' => ['required', 'boolean']]);
        $this->workflow->cardBetting($request, $game->id, $card->id);

        return $this->success($game->id, 'Card updated.');
    }

    public function subtractCards(Request $request, EndingGame $game, EndingBetAmount $betAmount): RedirectResponse
    {
        $this->permit($request, 12);
        $request->validate(['quantity' => ['required', 'integer', 'between:1,1000']]);
        $this->workflow->subtractCards($request, $game->id, $betAmount->id);

        return $this->success($game->id, 'Unused cards subtracted.');
    }

    public function result(SaveEndingResultRequest $request, EndingGame $game): RedirectResponse
    {
        $this->workflow->result($request, $game->id);

        return $this->success($game->id, 'Result created.');
    }

    public function updateResult(SaveEndingResultRequest $request, EndingGame $game, EndingGameResult $result): RedirectResponse
    {
        $this->workflow->result($request, $game->id, $result->id);

        return $this->success($game->id, 'Result corrected.');
    }

    public function winner(Request $request, EndingGame $game, string $action): RedirectResponse
    {
        $this->permit($request, $action === 'approve' ? 9 : 8);
        $request->validate(['bet_id' => ['required', 'integer', 'min:1'], 'quarter_id' => ['required', 'integer', 'between:1,4']]);
        $this->workflow->winner($request, $game->id, $action);

        return $this->success($game->id, $action === 'approve'
            ? 'Winner approved and declared. Prize release is recorded separately.' : 'Winner updated.');
    }

    private function success(int $gameId, string $message): RedirectResponse
    {
        Inertia::flash('success', $message);

        return to_route('ending.games.show', ['game' => $gameId], 303);
    }

    private function permit(Request $request, int $permission): void
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $this->access->require($user, $permission);
    }

    /** @return array<string, mixed> */
    private function logData(AdminEndingLog|AdminEndingLegacyLog $log): array
    {
        return Arr::only($log->attributesToArray(), [
            'id', 'game_id', 'user_id', 'action', 'created_at', 'subject_type', 'subject_id', 'before', 'after',
            'reason', 'reference_id', 'details', 'reject_reason', 'remarks',
        ]) + ['actor_name' => $log->actor?->name];
    }

    /** @return array<int, array<string, mixed>> */
    private function cardBets(EndingCard $card): array
    {
        return $card->bets()->with(['player:id,name', 'slot:id,slot'])->orderBy('slot_id')->orderBy('quarter_id')->orderBy('id')
            ->get(['id', 'card_id', 'player_id', 'legacy_player_id', 'slot_id', 'quarter_id', 'name', 'result', 'refunded'])
            ->map(fn (EndingBet $bet) => [
                'id' => $bet->id, 'quarter_id' => $bet->quarter_id, 'name' => $bet->name,
                'player_id' => $bet->player_id, 'legacy_player_id' => $bet->legacy_player_id,
                'result' => $bet->result, 'refunded' => $bet->refunded, 'player_name' => $bet->player?->name,
                'combination' => $bet->slot?->slot,
            ])->all();
    }
}
