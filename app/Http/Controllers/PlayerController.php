<?php

namespace App\Http\Controllers;

use App\Models\Player;
use App\Services\Players\PagcorLookup;
use App\Services\Players\PlayerQueries;
use App\Services\Players\PlayerWorkflow;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PlayerController extends Controller
{
    public function __construct(private PlayerQueries $queries, private PlayerWorkflow $workflow) {}

    public function index(Request $request): Response
    {
        Gate::authorize('players.view');
        $filters = $this->listFilters($request);

        return Inertia::render('players/List', [
            'players' => $this->queries->filtered($filters)->paginate($request->integer('per_page') ?: 10)->withQueryString(),
            'filters' => $filters,
        ]);
    }

    public function withdrawalAccounts(Request $request): Response
    {
        Gate::authorize('players.view');
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', ...($request->filled('date_from') ? ['after_or_equal:date_from'] : [])],
            'sort' => ['nullable', Rule::in(['bank', 'account_number', 'account_name', 'is_verified', 'created_at'])],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'per_page' => ['nullable', 'integer', Rule::in([5, 10, 15, 20, 25])],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $filters += ['search' => '', 'date_from' => '', 'date_to' => '', 'sort' => 'created_at', 'direction' => 'desc'];
        $firstCreatedAt = DB::table('admin_player_fund_accounts')->min('created_at');

        return Inertia::render('players/WithdrawalAccounts', [
            'accounts' => $this->queries->withdrawalAccounts($filters)->paginate($filters['per_page'] ?? 5)->withQueryString(),
            'filters' => $filters,
            'firstAccountDate' => $firstCreatedAt ? CarbonImmutable::parse($firstCreatedAt, 'UTC')->setTimezone('Asia/Manila')->format('Y-m-d') : null,
            'today' => now('Asia/Manila')->format('Y-m-d'),
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        Gate::authorize('players.view');
        $query = $this->queries->filtered($this->listFilters($request));

        return response()->streamDownload(function () use ($query): void {
            $stream = fopen('php://output', 'wb');
            if ($stream === false) {
                throw new \RuntimeException('The export stream could not be opened.');
            }
            fwrite($stream, "\xEF\xBB\xBF");
            fputcsv($stream, ['ID', 'NAME', 'MOBILE NUMBER', 'EMAIL ADDRESS', 'VERIFICATION STATUS', 'VERIFIED DATE', 'ACCOUNT STATUS', 'REGISTERED DATE'], escape: '');
            foreach ($query->lazy(1000) as $row) {
                $values = [$row->id, $row->name, $row->mobile_number, $row->email_address, $row->account_verified,
                    $this->displayDate($row->verified_at), $row->active_status, $this->displayDate($row->created_at)];
                fputcsv($stream, array_map(fn ($value) => preg_match('/^\s*[=+@-]/u', (string) $value) ? "'".$value : $value, $values), escape: '');
            }
            fclose($stream);
        }, 'players-list-report-'.now('Asia/Manila')->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function duplicates(Request $request, Player $player): Response
    {
        $request->merge([
            'duplicate_of' => $player->id, 'status' => $request->input('status', 'All'),
            'account_verified' => $request->input('account_verified', 'All'),
            'date_from' => $request->input('date_from'), 'date_to' => $request->input('date_to'),
        ]);

        return $this->index($request);
    }

    public function show(Request $request, Player $player): Response
    {
        Gate::authorize('players.view');
        $filters = $request->validate([
            'tab' => ['nullable', Rule::in(['transactions', 'player_logs', 'admin_logs'])],
            'activity_search' => ['nullable', 'string', 'max:100'],
            'activity_date_from' => ['nullable', 'date_format:Y-m-d'],
            'activity_date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:activity_date_from'],
            'per_page' => ['nullable', 'integer', Rule::in([5, 10, 25, 50, 100])],
        ]);

        return Inertia::render('players/Details', [
            'player' => fn () => $this->queries->details($player->id),
            'fundAccounts' => fn () => DB::table('admin_player_fund_accounts as f')->leftJoin('admin_player_banks as b', 'b.id', '=', 'f.bank_id')
                ->where('f.player_id', $player->id)->orderByDesc('f.id')->get(['f.id', 'f.account_number', 'f.account_name', 'f.is_verified', 'f.is_active', 'f.is_default', 'b.bank', 'b.type']),
            'rejectReasons' => fn () => DB::table('admin_player_reject_reasons')->where('is_active', 'Yes')->orderBy('reason')->get(['id', 'reason']),
            'activity' => fn () => $this->queries->activity($player->id, $filters)->paginate($request->integer('per_page') ?: 10)->withQueryString(),
            'filters' => $filters,
        ]);
    }

    public function ndrp(Request $request, Player $player, PagcorLookup $lookup): JsonResponse
    {
        Gate::authorize('players.view');

        return response()->json($lookup->search((string) $player->name));
    }

    public function image(Request $request, Player $player, string $kind): BinaryFileResponse
    {
        Gate::authorize('players.view');
        $path = DB::table('player_profiles')->where('player_id', $player->id)->value($kind.'_path');
        $configuredRoot = config('players.media_root');
        $root = is_string($configuredRoot) && $configuredRoot !== '' ? realpath($configuredRoot) : false;
        abort_unless($root && is_string($path) && str_starts_with(str_replace('\\', '/', $path), 'players/'.$player->id.'/'), 404);
        $file = realpath($root.DIRECTORY_SEPARATOR.$path);
        $folder = $root.DIRECTORY_SEPARATOR.'players'.DIRECTORY_SEPARATOR.$player->id.DIRECTORY_SEPARATOR;
        abort_unless($file && is_file($file) && str_starts_with(strtolower($file), strtolower($folder)) && filesize($file) <= 10 * 1024 * 1024, 404);
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($file);
        abort_unless(in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true), 404);

        return response()->file($file, ['Content-Type' => $mime, 'Cache-Control' => 'no-store, private', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function verify(Request $request, Player $player): RedirectResponse
    {
        return $this->evaluate($request, $player, false);
    }

    public function reject(Request $request, Player $player): RedirectResponse
    {
        return $this->evaluate($request, $player, true);
    }

    public function setting(Request $request, Player $player, string $setting): RedirectResponse
    {
        Gate::authorize('players.view');
        Gate::authorize('players.settings.update');
        $request->validate([
            'enabled' => ['required', 'boolean'], 'expected' => ['required', 'string', 'max:32'],
            'reason' => [Rule::requiredIf($setting === 'account' && ! $request->boolean('enabled')), 'nullable', 'string', 'max:50'],
        ]);
        $this->workflow->setting($request, $player->id, $setting);

        return $this->success($player, 'Player setting updated.');
    }

    public function fund(Request $request, Player $player, int $fund, string $action): RedirectResponse
    {
        Gate::authorize('players.view');
        Gate::authorize('players.verify');
        $request->validate(['reason' => [Rule::requiredIf($action === 'reject'), 'nullable', 'string', 'max:500']]);
        $this->workflow->fund($request, $player->id, $fund, $action);

        return $this->success($player, 'Withdrawal account '.($action === 'verify' ? 'verified.' : 'rejected.'));
    }

    private function evaluate(Request $request, Player $player, bool $reject): RedirectResponse
    {
        Gate::authorize('players.view');
        Gate::authorize('players.verify');
        $request->validate([
            'expected' => ['required', Rule::in(['Pending', 'Files Submitted'])],
            'reject_reason_id' => [Rule::requiredIf($reject), 'nullable', 'integer', Rule::exists('admin_player_reject_reasons', 'id')->where('is_active', 'Yes')],
        ]);
        $this->workflow->verify($request, $player->id, $reject);

        return $this->success($player, $reject ? 'Player rejected. The account is pending updated verification details.' : 'Player verified.');
    }

    /** @return array<string, mixed> */
    private function listFilters(Request $request): array
    {
        $request->merge([
            'status' => $request->input('status', 'Active'),
            'account_verified' => $request->input('account_verified', 'Files Submitted'),
            'date_from' => $request->input('date_from', '2024-07-01'),
            'date_to' => $request->input('date_to', now('Asia/Manila')->format('Y-m-d')),
        ]);

        return $request->validate([
            'search' => ['nullable', 'string', 'max:100'], 'status' => ['required', Rule::in(['All', 'Active', 'Inactive'])],
            'account_verified' => ['required', Rule::in(['All', 'Verified', 'Pending', 'Files Submitted', 'Rejected'])],
            'type' => ['nullable', Rule::in(['Players', 'Duplicate Players'])],
            'duplicate_of' => ['nullable', 'integer', 'min:1', Rule::exists('players', 'id')],
            'date_from' => ['nullable', 'date_format:Y-m-d'], 'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'sort' => ['nullable', Rule::in(['id', 'name', 'account_verified', 'verified_at', 'created_at'])],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'per_page' => ['nullable', 'integer', Rule::in([5, 10, 25, 50, 100])],
        ]);
    }

    private function success(Player $player, string $message): RedirectResponse
    {
        Inertia::flash('success', $message);

        return to_route('players.show', ['player' => $player->id], 303);
    }

    private function displayDate(?string $date): string
    {
        return $date ? CarbonImmutable::parse($date, 'UTC')->setTimezone('Asia/Manila')->format('M d, Y h:i A') : '';
    }
}
