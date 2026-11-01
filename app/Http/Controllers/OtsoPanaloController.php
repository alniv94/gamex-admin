<?php

namespace App\Http\Controllers;

use App\Http\Requests\SaveOtsoPanaloConfigRequest;
use App\Http\Requests\SaveOtsoPanaloGameRequest;
use App\Http\Requests\SaveOtsoPanaloResultRequest;
use App\Services\Legacy\OtsoPanaloImport;
use App\Services\OtsoPanalo\OtsoPanaloPdf;
use App\Services\OtsoPanalo\OtsoPanaloQueries;
use App\Services\OtsoPanalo\OtsoPanaloWorkflow;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class OtsoPanaloController extends Controller
{
    public function __construct(private OtsoPanaloQueries $queries, private OtsoPanaloWorkflow $workflow) {}

    public function index(Request $request): Response
    {
        Gate::authorize('Otso Panalo');
        $filters = $this->filters($request);
        $query = $this->queries->games();
        if ($request->filled('status')) {
            $query->where('g.game_status_id', $request->integer('status'));
        }
        if ($request->filled('search')) {
            $search = '%'.$request->string('search')->toString().'%';
            $query->where(fn ($q) => $q->where('g.id', 'like', $search)->orWhere('a.team_name', 'like', $search)->orWhere('b.team_name', 'like', $search)->orWhere('l.league', 'like', $search));
        }
        $this->queries->dates($query, $filters, 'e.schedule');
        $sortColumns = ['id' => 'g.id', 'schedule' => 'e.schedule', 'betting_deadline' => 'g.betting_deadline',
            'betting' => 'g.betting', 'game_status' => 's.name', 'bet_amount' => 'g.bet_amount',
            'jackpot_prize' => 'g.jackpot_prize', 'created_at' => 'g.created_at'];
        $query->orderBy($sortColumns[$filters['sort'] ?? 'created_at'], $filters['direction'] ?? 'desc')->orderByDesc('g.id');

        return Inertia::render('otso/Games', [
            'games' => $query->paginate($request->integer('per_page', 10))->withQueryString()->through(OtsoPanaloQueries::publicData(...)),
            'filters' => $filters, 'migrationReady' => $this->ready(),
            'availableGames' => $request->user()->can('Create Otso Panalo') ? $this->queries->available() : [],
            'activeConfig' => $this->activeConfig(),
        ]);
    }

    public function show(Request $request, int $game): Response
    {
        Gate::authorize('Otso Panalo');
        $row = $this->queries->games()->where('g.id', $game)->firstOrFail();
        $this->filters($request);
        $canResult = $request->user()->canAny(['Otso Panalo Game Result', 'Otso Panalo Create Result', 'Otso Panalo Update Result', 'Otso Panalo Approve Result']);
        $result = $canResult ? DB::table('otso_panalo_game_results')->where('game_id', $game)->first() : null;

        return Inertia::render('otso/Game', [
            'game' => OtsoPanaloQueries::publicData($row), 'result' => $result ? OtsoPanaloQueries::publicData($result) : null,
            'canViewResult' => $canResult,
            'resultStatus' => $canResult || $request->user()->can('Otso Panalo Game Winners') ? DB::table('otso_panalo_game_results')->where('game_id', $game)->value('status') : null,
            'availableGames' => $request->user()->can('Update Otso Panalo') ? $this->queries->available($game) : [],
            'activeConfig' => $this->activeConfig(), 'migrationReady' => $this->ready(),
            'summary' => [
                'bets' => DB::table('otso_panalo_bets')->where('game_id', $game)->count(),
                'total' => DB::table('otso_panalo_bets')->where('game_id', $game)->sum('amount'),
                'refunded' => DB::table('otso_panalo_bets')->where('game_id', $game)->where('refunded', 'Yes')->sum('amount'),
                'gross' => DB::table('otso_panalo_bets')->where('game_id', $game)->where('refunded', 'No')->sum('amount'),
                'winnings' => DB::table('otso_panalo_winners as w')->join('otso_panalo_bets as b', 'b.id', '=', 'w.bet_id')
                    ->where('b.game_id', $game)->where('b.refunded', 'No')->sum('w.prize_amount'),
            ],
            'winners' => $request->user()->can('Otso Panalo Game Winners') ? $this->queries->winners($game)->orderBy('w.winner_type_id')->orderBy('w.id')->paginate(25)->withQueryString()->through(OtsoPanaloQueries::publicData(...)) : null,
            'scores' => $canResult ? DB::table('ending_game_results')->where('game_id', $row->ending_game_id)->orderBy('quarter_id')->get(['quarter_id', 'team_a_score', 'team_b_score']) : [],
            'audits' => DB::table('admin_otso_panalo_audits as a')->leftJoin('users as u', 'u.id', '=', 'a.user_id')->where('a.game_id', $game)->orderByDesc('a.id')->limit(100)->get(['a.id', 'a.action', 'a.created_at', 'u.name as actor'])->map(OtsoPanaloQueries::publicData(...)),
            'legacyLogs' => DB::table('admin_otso_panalo_legacy_logs as a')->leftJoin('users as u', 'u.id', '=', 'a.user_id')->where('a.game_id', $game)->orderByDesc('a.created_at')->orderByDesc('a.id')->limit(100)->get(['a.id', 'a.action', 'a.details', 'a.created_at', 'u.name as actor'])->map(OtsoPanaloQueries::publicData(...)),
        ]);
    }

    public function configurations(Request $request): Response
    {
        Gate::authorize('Otso Panalo Game Types');
        $this->filters($request);

        return Inertia::render('otso/Configs', [
            'configs' => DB::table('otso_panalo_game_configs')->orderByDesc('id')->paginate($request->integer('per_page', 25))->withQueryString()->through(OtsoPanaloQueries::publicData(...)),
            'migrationReady' => $this->ready(),
        ]);
    }

    public function storeConfig(SaveOtsoPanaloConfigRequest $request): RedirectResponse
    {
        $this->workflow->config($request);

        return $this->success('Game configuration created.');
    }

    public function updateConfig(SaveOtsoPanaloConfigRequest $request, int $config): RedirectResponse
    {
        $this->workflow->config($request, $config);

        return $this->success('Game configuration updated.');
    }

    public function store(SaveOtsoPanaloGameRequest $request): RedirectResponse
    {
        $id = $this->workflow->saveGame($request);

        Inertia::flash('success', 'Otso Panalo game created.');

        return to_route('otso.games.show', $id, 303);
    }

    public function update(SaveOtsoPanaloGameRequest $request, int $game): RedirectResponse
    {
        $this->workflow->saveGame($request, $game);

        return $this->success('Game updated.');
    }

    public function betting(Request $request, int $game): RedirectResponse
    {
        Gate::authorize('Otso Panalo Game Settings');
        $request->validate(['enabled' => ['required', 'boolean']]);
        $this->workflow->betting($request, $game);

        return $this->success('Betting updated.');
    }

    public function storeResult(SaveOtsoPanaloResultRequest $request, int $game): RedirectResponse
    {
        $this->workflow->result($request, $game);

        return $this->success('Result submitted for approval.');
    }

    public function updateResult(SaveOtsoPanaloResultRequest $request, int $game): RedirectResponse
    {
        $this->workflow->result($request, $game, true);

        return $this->success('Pending result updated.');
    }

    public function approveResult(Request $request, int $game): RedirectResponse
    {
        Gate::authorize('Otso Panalo Approve Result');
        $request->validate(['version' => ['required', 'integer', 'min:1']]);
        $this->workflow->approveResult($request, $game);

        return $this->success('Result approved.');
    }

    public function generate(Request $request, int $game): RedirectResponse
    {
        Gate::authorize('Otso Panalo Game Winners');
        $count = $this->workflow->generate($request, $game);

        return $this->success($count ? $count.' winners generated.' : 'Winner computation complete. No new winners to add.');
    }

    public function approveWinners(Request $request, int $game): RedirectResponse
    {
        Gate::authorize('Otso Panalo Game Winner Approval');
        $request->validate(['winner_id' => ['nullable', 'integer', 'min:1']]);
        $this->workflow->approveWinners($request, $game, $request->filled('winner_id') ? $request->integer('winner_id') : null);

        return $this->success('Winners approved. Eligible secondary prizes were credited.');
    }

    public function cancel(Request $request, int $game): RedirectResponse
    {
        Gate::authorize('Otso Panalo Game Settings');
        $request->validate(['reason' => ['required', Rule::in(OtsoPanaloWorkflow::CANCELLATION_REASONS)]]);
        $this->workflow->cancel($request, $game);

        return $this->success('Game cancelled and wagers refunded.');
    }

    public function bets(Request $request): Response
    {
        Gate::authorize('Otso Panalo');
        $filters = $this->filters($request);

        return Inertia::render('otso/Bets', [
            'bets' => $this->queries->filteredBets($filters)->paginate($request->integer('per_page', 25))->withQueryString()->through(OtsoPanaloQueries::publicData(...)),
            'games' => $this->queries->games()->orderByDesc('e.schedule')->limit(500)->get()->map(OtsoPanaloQueries::publicData(...)),
            'filters' => $filters,
        ]);
    }

    public function importBets(Request $request, int $game, OtsoPanaloImport $import): RedirectResponse
    {
        Gate::authorize('Create Otso Panalo');
        try {
            $count = $this->workflow->importBets($request, $game, $import);
        } catch (RuntimeException $exception) {
            report($exception);

            return back()->withErrors(['action' => 'The import failed an integrity check. Run the legacy import command to inspect conflicts.']);
        }

        return $this->success($count.' additional legacy bets imported.');
    }

    public function pdf(Request $request, int $game, OtsoPanaloPdf $pdf): \Illuminate\Http\Response
    {
        Gate::authorize('Ending Game PDF Generation');
        Gate::authorize('Otso Panalo');
        $row = $this->queries->games()->where('g.id', $game)->firstOrFail();

        return response($pdf->render($row), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="otso_panalo_bets_'.$game.'.pdf"', 'Cache-Control' => 'private, no-store']);
    }

    public function exportBets(Request $request): StreamedResponse
    {
        Gate::authorize('Otso Panalo');
        $query = $this->queries->filteredBets($this->filters($request));

        return response()->streamDownload(function () use ($query): void {
            $stream = fopen('php://output', 'wb');
            if ($stream === false) {
                throw new RuntimeException('The CSV output stream could not be opened.');
            }
            fwrite($stream, "\xEF\xBB\xBF");
            fputcsv($stream, ['ID', 'Player', 'Game ID', 'League', 'Team A', 'Team B', 'Schedule', 'Bet Type', 'Ticket Name', 'Q1', 'Q2', 'Q3', 'Q4', 'Amount', 'Refunded', 'Winner Status', 'Prize Amount', 'Bet At'], escape: '');
            foreach ($query->cursor() as $bet) {
                $values = [$bet->id, $bet->username ?: $bet->player_name, $bet->game_id, $bet->league, $bet->team_a_name, $bet->team_b_name,
                    $bet->schedule, $bet->bet_type, $bet->name, $bet->first_quarter_combination, $bet->second_quarter_combination,
                    $bet->third_quarter_combination, $bet->fourth_quarter_combination, $bet->amount, $bet->refunded, $bet->winner_status, $bet->prize_amount, $bet->created_at];
                foreach ([6, 17] as $index) {
                    $values[$index] = $values[$index] ? CarbonImmutable::parse($values[$index], 'UTC')->timezone('Asia/Manila')->format('Y-m-d H:i:s') : '';
                }
                foreach ($values as $index => $value) {
                    $text = (string) $value;
                    if (preg_match('/^\s*[=+@\-]|^[\t\r\n]/u', $text) || ($index >= 9 && $index <= 12 && $text !== '')) {
                        $values[$index] = "'".$text;
                    }
                }
                fputcsv($stream, $values, escape: '');
            }
            fclose($stream);
        }, 'otso_panalo_bets.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store']);
    }

    private function ready(): bool
    {
        return DB::table('admin_otso_panalo_imports')->where('source', config('otso.legacy_source'))->exists();
    }

    private function success(string $message): RedirectResponse
    {
        Inertia::flash('success', $message);

        return back(303);
    }

    /** @return array<string, mixed>|null */
    private function activeConfig(): ?array
    {
        $config = DB::table('otso_panalo_game_configs')->where('status', 'Active')->orderByDesc('updated_at')->orderByDesc('id')->first();

        return $config ? OtsoPanaloQueries::publicData($config) : null;
    }

    /** @return array<string, mixed> */
    private function filters(Request $request): array
    {
        $rules = ['search' => ['nullable', 'string', 'max:200'], 'status' => ['nullable', 'integer', 'between:1,4'],
            'sort' => ['nullable', Rule::in(['id', 'schedule', 'betting_deadline', 'betting', 'game_status', 'bet_amount', 'jackpot_prize', 'created_at'])],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'game_id' => ['nullable', 'integer', 'exists:otso_panalo_games,id'], 'filter_by' => ['nullable', 'in:Bet Date,Game Schedule'],
            'date_from' => ['nullable', 'date_format:Y-m-d'], 'date_to' => ['nullable', 'date_format:Y-m-d'],
            'per_page' => ['nullable', 'integer', 'between:1,500'], 'page' => ['nullable', 'integer', 'min:1']];
        if ($request->filled('date_from')) {
            $rules['date_to'][] = 'after_or_equal:date_from';
        }

        return $request->validate($rules);
    }
}
