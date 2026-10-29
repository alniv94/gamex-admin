<?php

namespace App\Http\Controllers;

use App\Models\AdminEndingImport;
use App\Models\AdminEndingLog;
use App\Models\EndingConfig;
use App\Models\EndingLeague;
use App\Models\EndingPerQuarterBetAmountConfig;
use App\Models\EndingRecord;
use App\Models\EndingTeam;
use App\Models\EndingTraditionalBetAmountConfig;
use App\Models\User;
use App\Services\Ending\EndingAccess;
use App\Services\Ending\EndingWorkflow;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class EndingCatalogController extends Controller
{
    public function __construct(private EndingAccess $access, private EndingWorkflow $workflow) {}

    public function teams(Request $request): Response
    {
        $this->permit($request, 18);
        $filters = $this->filters($request);
        $teams = EndingTeam::query()->with('league:id,league');
        if ($request->filled('search')) {
            $search = '%'.$request->string('search').'%';
            $teams->where(fn (Builder $query) => $query->where('team_name', 'like', $search)
                ->orWhere('team_alias', 'like', $search)
                ->orWhereHas('league', fn (Builder $leagues) => $leagues->where('league', 'like', $search)));
        }

        return Inertia::render('ending/Teams', [
            'records' => $teams->orderByDesc('created_at')->orderByDesc('id')->paginate($request->integer('per_page', 5))->withQueryString(),
            'leagues' => EndingLeague::query()->orderBy('league')->get(['id', 'league', 'is_active']),
            'filters' => $filters, 'migrationReady' => $this->ready(),
        ]);
    }

    public function storeTeam(Request $request): RedirectResponse
    {
        return $this->saveTeam($request, new EndingTeam);
    }

    public function updateTeam(Request $request, EndingTeam $team): RedirectResponse
    {
        return $this->saveTeam($request, $team);
    }

    public function leagues(Request $request): Response
    {
        $this->permit($request, 19);
        $filters = $this->filters($request);
        $leagues = EndingLeague::query();
        if ($request->filled('search')) {
            $leagues->where('league', 'like', '%'.$request->string('search').'%');
        }

        return Inertia::render('ending/Leagues', [
            'records' => $leagues->orderByDesc('created_at')->orderByDesc('id')->paginate($request->integer('per_page', 5))->withQueryString(),
            'filters' => $filters, 'migrationReady' => $this->ready(),
        ]);
    }

    public function storeLeague(Request $request): RedirectResponse
    {
        return $this->saveLeague($request, new EndingLeague);
    }

    public function updateLeague(Request $request, EndingLeague $league): RedirectResponse
    {
        return $this->saveLeague($request, $league);
    }

    public function configuration(Request $request): Response
    {
        $this->permit($request, 20);
        $presets = [];
        foreach ([1 => EndingTraditionalBetAmountConfig::class, 2 => EndingPerQuarterBetAmountConfig::class] as $type => $model) {
            foreach ($model::query()->orderBy('amount')->get(['id', 'amount', 'card_quantity', 'status']) as $preset) {
                $presets[] = ['id' => $preset->id, 'bet_type_id' => $type, 'amount' => $preset->amount, 'card_quantity' => $preset->card_quantity, 'status' => $preset->status];
            }
        }

        return Inertia::render('ending/Config', [
            'records' => EndingConfig::query()->whereIn('config_name', EndingConfig::EDITABLE_NAMES)->orderBy('id')->get(['id', 'config_name', 'config_desc', 'config_value']),
            'configNames' => EndingConfig::EDITABLE_NAMES,
            'presets' => $presets,
            'migrationReady' => $this->ready(),
        ]);
    }

    public function storeConfig(Request $request): RedirectResponse
    {
        return $this->saveConfig($request, new EndingConfig);
    }

    public function updateConfig(Request $request, EndingConfig $config): RedirectResponse
    {
        abort_unless(in_array($config->config_name, EndingConfig::EDITABLE_NAMES, true), 404);

        return $this->saveConfig($request, $config);
    }

    public function storePreset(Request $request): RedirectResponse
    {
        $this->permit($request, 20);
        $request->validate(['bet_type_id' => ['required', 'integer', Rule::in([1, 2])]]);

        return $this->savePreset($request, $request->integer('bet_type_id'));
    }

    public function updatePreset(Request $request, int $type, int $preset): RedirectResponse
    {
        $this->permit($request, 20);

        return $this->savePreset($request, $type, $preset);
    }

    public function deletePreset(Request $request, int $type, int $preset): RedirectResponse
    {
        $this->permit($request, 20);
        $this->workflow->write(null, function () use ($request, $type, $preset): void {
            $record = $this->presetModel($type)::query()->lockForUpdate()->findOrFail($preset);
            $before = $record->attributesToArray();
            $record->delete();
            $this->audit($request, $record, 'delete_preset', $before, null);
        });

        return $this->success('ending.config', 'Preset deleted.');
    }

    private function saveTeam(Request $request, EndingTeam $team): RedirectResponse
    {
        $this->permit($request, 18);
        $this->workflow->write(null, function () use ($request, $team): void {
            if ($team->exists) {
                $team = EndingTeam::query()->lockForUpdate()->findOrFail($team->id);
            }
            $data = $request->validate([
                'league_id' => ['required', 'integer', Rule::exists('ending_leagues', 'id')],
                'team_name' => ['required', 'string', 'min:2', 'max:255', Rule::unique('ending_teams')->where('league_id', $request->integer('league_id'))->ignore($team->id)],
                'team_alias' => ['nullable', 'string', 'min:2', 'max:255', Rule::unique('ending_teams')->where('league_id', $request->integer('league_id'))->ignore($team->id)],
                'logo' => ['required', 'string', 'max:2048', 'url:http,https'],
                'is_active' => ['required', Rule::in(['Active', 'Inactive'])],
            ]);
            $this->persist($request, $team, $data, 'team');
        });

        return $this->success('ending.teams.index', 'Team saved.');
    }

    private function saveLeague(Request $request, EndingLeague $league): RedirectResponse
    {
        $this->permit($request, 19);
        $this->workflow->write(null, function () use ($request, $league): void {
            if ($league->exists) {
                $league = EndingLeague::query()->lockForUpdate()->findOrFail($league->id);
            }
            $data = $request->validate([
                'league' => ['required', 'string', 'min:2', 'max:255', Rule::unique('ending_leagues')->ignore($league->id)],
                'logo' => ['required', 'string', 'max:2048', 'url:http,https'],
                'is_local' => ['required', Rule::in(['Local', 'International'])],
                'is_active' => ['required', Rule::in(['Active', 'Inactive'])],
            ]);
            $this->persist($request, $league, $data, 'league');
        });

        return $this->success('ending.leagues.index', 'League saved.');
    }

    private function saveConfig(Request $request, EndingConfig $config): RedirectResponse
    {
        $this->permit($request, 20);
        $this->workflow->write(null, function () use ($request, $config): void {
            if ($config->exists) {
                $config = EndingConfig::query()->lockForUpdate()->findOrFail($config->id);
            }
            $data = $request->validate([
                'config_name' => ['required', Rule::in($config->exists ? [$config->config_name] : EndingConfig::EDITABLE_NAMES), Rule::unique('ending_config')->ignore($config->id)],
                'config_desc' => ['nullable', 'string', 'max:1000'],
                'config_value' => ['required', 'numeric', 'min:0', 'max:1000000', 'decimal:0,2'],
            ]);
            $before = $config->exists ? $config->attributesToArray() : null;
            $config->forceFill($data)->save();
            $this->audit($request, $config, $before === null ? 'create_config' : 'update_config', $before, $config->attributesToArray());
        });

        return $this->success('ending.config', 'Configuration saved.');
    }

    private function savePreset(Request $request, int $type, ?int $id = null): RedirectResponse
    {
        $this->workflow->write(null, function () use ($request, $type, $id): void {
            $model = $this->presetModel($type);
            $preset = $id === null ? new $model : $model::query()->lockForUpdate()->findOrFail($id);
            $data = $request->validate([
                'amount' => ['required', 'numeric', 'decimal:0,2', 'gt:0', 'max:99999999.99', Rule::unique($preset->getTable())->ignore($preset->id)],
                'card_quantity' => ['required', 'integer', 'between:0,1000'],
                'status' => ['required', Rule::in(['Active', 'Inactive'])],
            ]);
            $before = $preset->exists ? $preset->attributesToArray() : null;
            $preset->forceFill($data)->save();
            $this->audit($request, $preset, $before === null ? 'create_preset' : 'update_preset', $before, $preset->attributesToArray());
        });

        return $this->success('ending.config', 'Preset saved.');
    }

    /** @param array<string, mixed> $data */
    private function persist(Request $request, EndingTeam|EndingLeague $record, array $data, string $subject): void
    {
        $before = $record->exists ? $record->attributesToArray() : null;
        $record->fill($data);
        if (! $record->exists) {
            $record->created_at = now();
        }
        $record->save();
        $this->audit($request, $record, ($before === null ? 'create_' : 'update_').$subject, $before, $record->attributesToArray());
    }

    /** @param array<string, mixed>|null $before
     * @param  array<string, mixed>|null  $after
     */
    private function audit(Request $request, EndingRecord $record, string $action, ?array $before, ?array $after): void
    {
        AdminEndingLog::query()->create([
            'game_id' => null, 'user_id' => $request->user()?->id, 'action' => $action,
            'subject_type' => $record->getTable(), 'subject_id' => $record->id,
            'before' => $before, 'after' => $after, 'ip_address' => $request->ip(), 'created_at' => now(),
        ]);
    }

    /** @return class-string<EndingTraditionalBetAmountConfig>|class-string<EndingPerQuarterBetAmountConfig> */
    private function presetModel(int $type): string
    {
        abort_unless(in_array($type, [1, 2], true), 404);

        return $type === 1 ? EndingTraditionalBetAmountConfig::class : EndingPerQuarterBetAmountConfig::class;
    }

    /** @return array<string, mixed> */
    private function filters(Request $request): array
    {
        return $request->validate(['search' => ['nullable', 'string', 'max:100'], 'per_page' => ['sometimes', 'integer', Rule::in([5, 10, 25, 50, 100])]]);
    }

    private function ready(): bool
    {
        return AdminEndingImport::query()->where('source', config('ending.legacy_source'))->exists();
    }

    private function permit(Request $request, int $permission): void
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $this->access->require($user, $permission);
    }

    private function success(string $route, string $message): RedirectResponse
    {
        Inertia::flash('success', $message);

        return to_route($route, [], 303);
    }
}
