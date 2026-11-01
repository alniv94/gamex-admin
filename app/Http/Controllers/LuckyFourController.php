<?php

namespace App\Http\Controllers;

use App\Models\EndingGame;
use App\Services\LuckyFour\LuckyFourQueries;
use App\Services\LuckyFour\LuckyFourWorkflow;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class LuckyFourController extends Controller
{
    public function __construct(private LuckyFourQueries $queries, private LuckyFourWorkflow $workflow) {}

    public function index(Request $request): Response
    {
        Gate::authorize('Lucky Four');
        $filters = $this->filters($request);
        $games = $this->queries->games();
        if ($request->filled('search')) {
            $search = '%'.$request->string('search').'%';
            $games->where(fn ($q) => $q->where('id', $request->input('search'))
                ->orWhereHas('teamA', fn ($team) => $team->where('team_name', 'like', $search))
                ->orWhereHas('teamB', fn ($team) => $team->where('team_name', 'like', $search)));
        }
        match ($request->input('status')) {
            'scheduled' => $games->whereNull('game_status_id'),
            'ongoing' => $games->whereBetween('game_status_id', [1, 4]),
            'finished' => $games->where('game_status_id', 5),
            default => null,
        };
        foreach (['date_from' => '>=', 'date_to' => '<='] as $field => $operator) {
            if ($request->filled($field)) {
                $date = CarbonImmutable::parse($request->string($field)->toString(), 'Asia/Manila');
                $games->where('schedule', $operator, ($field === 'date_to' ? $date->endOfDay() : $date->startOfDay())->utc());
            }
        }

        return Inertia::render('lucky-four/Games', ['games' => $games->orderByDesc('schedule')->orderByDesc('id')
            ->paginate($request->integer('per_page', 25))->withQueryString()->through(fn (EndingGame $game) => $game->summary()),
            'filters' => $filters, 'migrationReady' => $this->queries->ready()]);
    }

    public function show(Request $request, EndingGame $game): Response
    {
        Gate::authorize('Lucky Four');
        abort_unless($game->is_lucky_four === 'Yes', 404);
        $prizes = DB::table('lucky_four_prizes')->where('game_id', $game->id)->first();
        $bets = DB::table('lucky_four_bets')->where('game_id', $game->id);

        return Inertia::render('lucky-four/Game', [
            'game' => $game->loadMissing(['teamA.league', 'teamB', 'gameStatus'])->summary(),
            'prizes' => $prizes ? LuckyFourQueries::publicData($prizes) : null,
            'results' => DB::table('ending_game_results')->where('game_id', $game->id)->orderBy('quarter_id')->get(['id', 'quarter_id', 'team_a_score', 'team_b_score', 'lucky_four_result']),
            'combination' => $this->queries->result($game->id),
            'totals' => ['bets' => (clone $bets)->count(), 'amount' => (clone $bets)->sum('amount'),
                'pending' => (clone $bets)->where('is_added', 'No')->count(),
                'released' => DB::table('lucky_four_winners as w')->join('lucky_four_bets as b', 'b.ending_bet_id', '=', 'w.ending_bet_id')
                    ->where('b.game_id', $game->id)->where('w.prize_released', 'Yes')->sum('w.prize_amount')],
            'winners' => $this->queries->winners($game->id)->orderBy('l.id')->paginate(25)->withQueryString()->through(LuckyFourQueries::publicData(...)),
            'audits' => DB::table('admin_lucky_four_audits as a')->leftJoin('users as u', 'u.id', '=', 'a.user_id')->where('a.game_id', $game->id)
                ->orderByDesc('a.id')->limit(100)->get(['a.id', 'a.action', 'a.created_at', 'u.name as actor'])->map(LuckyFourQueries::publicData(...)),
            'legacyLogs' => DB::table('admin_lucky_four_legacy_logs')->where('game_id', $game->id)->orderByDesc('created_at')->orderByDesc('id')
                ->limit(100)->get(['id', 'action', 'details', 'created_at'])->map(LuckyFourQueries::publicData(...)),
            'migrationReady' => $this->queries->ready(),
        ]);
    }

    public function toggle(Request $request, EndingGame $game): RedirectResponse
    {
        Gate::authorize('Lucky Four Enable Disable');
        $request->validate(['enabled' => ['required', 'boolean']]);
        $this->workflow->toggle($request, $game->id);

        return $this->success('Lucky Four '.($request->boolean('enabled') ? 'enabled.' : 'disabled.'));
    }

    public function settings(): Response
    {
        Gate::authorize('Lucky Four Prizes Settings');
        $settings = DB::table('lucky_four_prizes_settings')->where('id', 1)->first();

        return Inertia::render('lucky-four/Settings', ['settings' => $settings ? LuckyFourQueries::publicData($settings) : null,
            'logs' => DB::table('lucky_four_prizes_setting_logs')->orderByDesc('id')->paginate(25)->through(LuckyFourQueries::publicData(...)),
            'migrationReady' => $this->queries->ready()]);
    }

    public function updateSettings(Request $request): RedirectResponse
    {
        Gate::authorize('Lucky Four Prizes Settings');
        $rules = [];
        foreach (LuckyFourWorkflow::SETTINGS as $field) {
            $rules[$field] = ['required', 'numeric', 'decimal:0,2', 'min:'.($field === 'bet_amount' ? '0.01' : '0'), 'max:'.($field === 'percentage_deduction' ? '100' : '99999999.99')];
        }
        $this->workflow->settings($request, $request->validate($rules));

        return $this->success('Lucky Four prize settings saved. Existing game prizes are unchanged.');
    }

    public function refresh(Request $request, EndingGame $game): RedirectResponse
    {
        Gate::authorize('Lucky Four Enable Disable');
        $this->workflow->refresh($request, $game->id);

        return $this->success('Jackpot refreshed. Eligible bets were added once.');
    }

    public function winner(Request $request, EndingGame $game, string $action): RedirectResponse
    {
        Gate::authorize($action === 'approve' ? 'Lucky Four Winner Approval' : 'Lucky Four Winner Verification');
        $request->validate(['ending_bet_id' => ['required', 'integer', 'min:1']]);
        $this->workflow->winner($request, $game->id, $action);

        return $this->success(match ($action) {
            'approve' => 'Winner approved and prize credited to the player wallet.',
            'verify' => 'Lucky Four winner verified.',
            default => 'Lucky Four winner unverified.',
        });
    }

    public function bets(Request $request): Response
    {
        Gate::authorize('Lucky Four');
        $filters = $this->filters($request);

        return Inertia::render('lucky-four/Bets', ['bets' => $this->filteredBets($request)->orderByDesc('l.id')
            ->paginate($request->integer('per_page', 25))->withQueryString()->through(LuckyFourQueries::publicData(...)),
            'filters' => $filters, 'migrationReady' => $this->queries->ready()]);
    }

    public function exportBets(Request $request): StreamedResponse
    {
        Gate::authorize('Lucky Four');
        $this->filters($request);
        $query = $this->filteredBets($request)->orderBy('l.id');

        return response()->streamDownload(function () use ($query): void {
            $stream = fopen('php://output', 'wb');
            if ($stream === false) {
                throw new RuntimeException('The CSV output stream could not be opened.');
            }
            fwrite($stream, "\xEF\xBB\xBF");
            fputcsv($stream, ['ID', 'Ending Bet', 'Game', 'Player', 'Combination', 'Amount', 'Pool Status', 'Winner Status', 'Prize', 'Released', 'Bet At (Manila)'], escape: '');
            foreach ($query->cursor() as $bet) {
                $values = [$bet->id, $bet->ending_bet_id, $bet->game_id, $bet->player_name, "'".$bet->combination, $bet->amount, $bet->is_added,
                    $bet->winner_status, $bet->prize_amount, $bet->prize_released, $bet->created_at ? CarbonImmutable::parse($bet->created_at, 'UTC')->timezone('Asia/Manila')->format('Y-m-d H:i:s') : ''];
                foreach ($values as &$value) {
                    if (preg_match('/^\s*[=+@\-]|^[\t\r\n]/u', (string) $value)) {
                        $value = "'".$value;
                    }
                }
                unset($value);
                fputcsv($stream, $values, escape: '');
            }
            fclose($stream);
        }, 'lucky_four_bets.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store']);
    }

    private function filteredBets(Request $request): Builder
    {
        $query = $this->queries->bets($request->filled('game_id') ? $request->integer('game_id') : null);
        if ($request->filled('search')) {
            $search = '%'.$request->string('search').'%';
            $query->where(fn ($q) => $q->where('p.name', 'like', $search)->orWhere('l.combination', 'like', $search)->orWhere('l.ending_bet_id', $request->input('search')));
        }
        foreach (['date_from' => '>=', 'date_to' => '<='] as $field => $operator) {
            if ($request->filled($field)) {
                $date = CarbonImmutable::parse($request->string($field)->toString(), 'Asia/Manila');
                $query->where('b.created_at', $operator, ($field === 'date_to' ? $date->endOfDay() : $date->startOfDay())->utc());
            }
        }

        return $query;
    }

    /** @return array<string, mixed> */
    private function filters(Request $request): array
    {
        return $request->validate(['search' => ['nullable', 'string', 'max:100'], 'status' => ['nullable', 'in:scheduled,ongoing,finished'],
            'game_id' => ['nullable', 'integer', 'min:1'], 'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', ...($request->filled('date_from') ? ['after_or_equal:date_from'] : [])],
            'per_page' => ['nullable', 'integer', 'in:5,10,25,50,100'], 'page' => ['nullable', 'integer', 'min:1']]);
    }

    private function success(string $message): RedirectResponse
    {
        Inertia::flash('success', $message);

        return back(303);
    }
}
