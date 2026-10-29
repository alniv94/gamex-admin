<?php

namespace App\Services\Ending;

use App\Models\AdminEndingImport;
use App\Models\AdminEndingLog;
use App\Models\EndingBet;
use App\Models\EndingBetAmount;
use App\Models\EndingCard;
use App\Models\EndingConfig;
use App\Models\EndingGame;
use App\Models\EndingGameResult;
use App\Models\EndingGameWinner;
use App\Models\EndingLeague;
use App\Models\EndingPerQuarterBetAmountConfig;
use App\Models\EndingSlot;
use App\Models\EndingTeam;
use App\Models\EndingTraditionalBetAmountConfig;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EndingWorkflow
{
    public function __construct(private EndingQueries $queries) {}

    /** @template T
     * @param  Closure(?EndingGame): T  $operation
     * @return T
     */
    public function write(?int $gameId, Closure $operation): mixed
    {
        $lock = Cache::lock('ending:write', 30);
        $this->check($lock->get(), 'game', 'Ending games are being updated. Please try again shortly.');
        try {
            $this->check(AdminEndingImport::query()->where('source', config('ending.legacy_source'))->exists(), 'game', 'Historical ending games must be imported and verified before creating or changing games.');

            return DB::transaction(function () use ($gameId, $operation) {
                $game = $gameId === null ? null : EndingGame::query()->where('id', $gameId)->lockForUpdate()->first();
                abort_if($gameId !== null && ! $game, 404);

                return $operation($game);
            });
        } finally {
            $lock->release();
        }
    }

    public function create(Request $request): int
    {
        return $this->write(null, function () use ($request): int {
            $teams = $this->teams($request);
            $schedule = $this->schedule($request->string('schedule')->toString());
            $game = EndingGame::query()->create($teams + ['schedule' => $schedule, 'created_at' => now()]);
            $id = $game->id;
            $prizeConfig = EndingConfig::query()->pluck('config_value', 'config_name');
            $presets = $request->input('presets');
            if (is_array($presets)) {
                $allowed = [];
                foreach ([1 => EndingTraditionalBetAmountConfig::class, 2 => EndingPerQuarterBetAmountConfig::class] as $type => $table) {
                    foreach ($table::available()->pluck('id') as $configId) {
                        $allowed[$type.':'.$configId] = true;
                    }
                }
                $seen = [];
                foreach ($presets as $preset) {
                    $key = $preset['bet_type_id'].':'.$preset['config_id'];
                    $this->check(isset($allowed[$key]) && ! isset($seen[$key]), 'presets', 'The selected card presets changed or contain duplicates. Refresh and try again.');
                    $seen[$key] = true;
                }
            }
            $total = 0;
            foreach ([1 => EndingTraditionalBetAmountConfig::class, 2 => EndingPerQuarterBetAmountConfig::class] as $type => $table) {
                foreach ($table::available()->get() as $preset) {
                    $quantity = $preset->card_quantity;
                    if (is_array($presets)) {
                        $selection = collect($presets)->first(fn ($p) => (int) $p['bet_type_id'] === $type && (int) $p['config_id'] === (int) $preset->id);
                        $quantity = $selection['quantity'] ?? 0;
                    }
                    $total += (int) $quantity;
                    $this->check($total <= 10000, 'presets', 'A game may have at most 10,000 initial cards.');
                    if ($quantity > 0) {
                        $prizes = [];
                        foreach ([1 => '1st', 2 => '2nd', 3 => '3rd', 4 => '4th'] as $quarter => $label) {
                            $name = ($type === 1 ? 'Traditional' : 'Per Quarter')." Card - {$label} Quarter Prize Percent";
                            $multiplier = $prizeConfig->get($name);
                            $this->check(is_numeric($multiplier) && (float) $multiplier >= 0, 'presets', 'Prize configuration is incomplete.');
                            $prizes[] = ['quarter_id' => $quarter, 'is_cash' => 'Cash', 'cash_prize' => $this->multiply((string) $preset->amount, (string) $multiplier), 'other_prize' => null];
                        }
                        $this->cards($game, $type, (string) $preset->amount, (int) $quantity, $prizes);
                    }
                }
            }
            $this->log($request, $id, 'create_game', 'game', $id, null, $teams + ['schedule' => $schedule, 'cards' => $total]);

            return $id;
        });
    }

    public function update(Request $request, int $id): void
    {
        $this->write($id, function ($game) use ($request, $id): void {
            $this->check(! $game->game_status_id && ! $this->results($id)->exists(), 'game', 'A started game cannot be rescheduled or change teams.');
            $teams = $this->teams($request);
            if ((int) $game->team_a !== $teams['team_a'] || (int) $game->team_b !== $teams['team_b']) {
                $this->check(! $this->bets($id)->exists(), 'game', 'Teams cannot change after bets have been placed.');
            }
            $changes = $teams + ['schedule' => $this->schedule($request->string('schedule')->toString())];
            $before = $game->getAttributes();
            $game->update($changes);
            $this->log($request, $id, 'update_game', 'game', $id, $before, $changes);
        });
    }

    public function betting(Request $request, int $id): void
    {
        $this->write($id, function ($game) use ($request, $id): void {
            $enabled = $request->boolean('enabled');
            if ($enabled) {
                $cutoff = $this->cutoff();
                $this->check(! $game->game_status_id && $game->schedule && $game->schedule->subMinutes($cutoff)->isFuture(), 'betting', 'Betting can only open before the game cutoff.');
                $this->check(! $this->results($id)->exists(), 'betting', 'A game with results cannot reopen betting.');
            }
            $value = $enabled ? 'Enabled' : 'Disabled';
            if ($game->betting === $value) {
                return;
            }
            $before = ['betting' => $game->betting];
            $game->update(['betting' => $value, ($enabled ? 'enabled' : 'disabled').'_betting_at' => now()]);
            $this->log($request, $id, 'game_betting', 'game', $id, $before, ['betting' => $value]);
        });
    }

    public function addCards(Request $request, int $id): void
    {
        $this->write($id, function ($game) use ($request, $id): void {
            $this->check(! $game->game_status_id && $game->schedule && $game->schedule->isFuture() && ! $this->results($id)->exists(), 'cards', 'Cards can only be created before the game starts.');
            $amountId = $request->integer('bet_amount_id');
            if ($amountId) {
                abort_unless(EndingBetAmount::query()->where('id', $amountId)->where('game_id', $id)->exists(), 404);
                $this->insertCards($amountId, $request->integer('quantity'));
            } else {
                $amountId = $this->cards($game, $request->integer('bet_type_id'), $request->string('amount')->toString(), $request->integer('quantity'), $request->input('prizes'));
            }
            $this->log($request, $id, 'add_card', 'bet_amount', $amountId, null, ['quantity' => $request->integer('quantity')]);
        });
    }

    public function cardBetting(Request $request, int $id, int $cardId): void
    {
        $this->write($id, function ($game) use ($request, $id, $cardId): void {
            $card = $game->cards()->findOrFail($cardId);
            $this->check(! $game->game_status_id && $game->schedule && $game->schedule->isFuture(), 'card', 'Cards cannot change after the game starts.');
            $this->check(! $card->bets()->exists(), 'card', 'A card with bets cannot be disabled or reopened.');
            $betting = $request->boolean('enabled') ? 'Enabled' : 'Disabled';
            if ($card->betting === $betting) {
                return;
            }
            $before = ['betting' => $card->betting];
            $card->update(['betting' => $betting]);
            $this->log($request, $id, 'card_betting', 'card', $cardId, $before, ['betting' => $betting]);
        });
    }

    public function result(Request $request, int $id, ?int $resultId = null): void
    {
        $this->write($id, function ($game) use ($request, $id, $resultId): void {
            $this->check($game->schedule && $game->schedule->lte(now()), 'game', 'Results can only be entered after the scheduled start.');
            $this->check($game->betting === 'Disabled', 'betting', 'Close betting before entering a result.');
            $quarter = $request->integer('quarter_id');
            $before = null;
            if ($resultId) {
                $before = $this->results($id)->where('id', $resultId)->first();
                abort_unless($before !== null, 404);
                $this->check((int) $before->quarter_id === $quarter, 'quarter_id', 'A result cannot move to another quarter.');
                $this->check(! EndingGameWinner::forGame($id)->where('quarter_id', $quarter)->exists(), 'result', 'Unverify all winners for this quarter before correcting the score. Approved winners lock the result.');
                $this->check($request->filled('reason'), 'reason', 'Explain why this result is being corrected.');
            } else {
                $this->check(! $this->results($id)->where('quarter_id', $quarter)->exists(), 'quarter_id', 'This quarter already has a result.');
                $this->check($this->results($id)->count() === $quarter - 1, 'quarter_id', 'Enter results in quarter order.');
            }
            $this->check($this->results($id)->where('quarter_id', $quarter)->count() <= 1, 'result', 'This legacy quarter contains duplicate results and needs review.');
            $a = $request->integer('team_a_score');
            $b = $request->integer('team_b_score');
            $combination = ($a % 10).'-'.($b % 10);
            $slot = EndingSlot::query()->where('slot', $combination)->first();
            $this->check($slot !== null, 'result', 'The winning combination is missing from slot configuration.');
            $changes = ['game_id' => $id, 'quarter_id' => $quarter, 'team_a_score' => $a, 'team_b_score' => $b, 'slot_id' => $slot->id];
            $beforeSnapshot = $before?->getAttributes();
            if ($resultId) {
                $before->update($changes + ['updated_at' => now()]);
            } else {
                $resultId = $game->results()->create($changes + ['created_at' => now()])->id;
            }
            $game->update(['game_status_id' => ((int) $this->results($id)->max('quarter_id')) + 1]);
            $this->log($request, $id, $before ? 'update_result' : 'create_result', 'result', $resultId, $beforeSnapshot, $changes + ['combination' => $combination]);
        });
    }

    public function winner(Request $request, int $id, string $action): void
    {
        $this->write($id, function () use ($request, $id, $action): void {
            $betId = $request->integer('bet_id');
            $quarter = $request->integer('quarter_id');
            $winnerRows = EndingGameWinner::query()->where('bet_id', $betId)->where('quarter_id', $quarter);
            $this->check($winnerRows->count() <= 1, 'winner', 'This legacy winner has duplicate records and needs review.');
            $winner = $winnerRows->first();
            if ($action === 'unverify') {
                $this->check($winner && (int) $winner->winner_status_id === 1 && $winner->prize_released !== 'Yes', 'winner', 'Only a verified, unreleased winner can be unverified.');
                $this->check($this->bets($id)->whereKey($betId)->exists(), 'winner', 'This winner does not belong to the game.');
                $winner->delete();
                $this->log($request, $id, 'unverify_winner', 'winner', $winner->id, $winner->getAttributes(), null);

                return;
            }
            $matches = $this->queries->winners($id)->where('b.id', $betId)->where('r.quarter_id', $quarter)->get();
            $this->check($matches->count() === 1, 'winner', 'A unique winning bet, result and prize are required.');
            $candidate = $matches->first();
            $this->check($candidate->eligible && $candidate->player_id !== null, 'winner', 'Resolve the player identity and confirm the winning combination before verification.');
            $this->check(($candidate->is_cash === 'Cash' && is_numeric($candidate->cash_prize) && (float) $candidate->cash_prize >= 0)
                || ($candidate->is_cash === 'Not Cash' && filled($candidate->other_prize)), 'winner', 'This winner has incomplete prize information and needs review.');
            if ($action === 'verify') {
                if ($winner) {
                    return;
                }
                $winnerId = EndingGameWinner::query()->create(['bet_id' => $betId, 'quarter_id' => $quarter, 'verified_by' => $request->user()->id, 'created_at' => now()])->id;
                $this->log($request, $id, 'verify_winner', 'winner', $winnerId, null, ['bet_id' => $betId, 'quarter_id' => $quarter, 'status' => 'Verified']);
            } else {
                $this->check($winner !== null, 'winner', 'Verify the winner before approval.');
                if ((int) $winner->winner_status_id === 2) {
                    return;
                }
                $this->check((int) $winner->winner_status_id === 1, 'winner', 'This winner has an unsupported status.');
                // Declaration ends this workflow. Payouts use a separate settlement process.
                $before = $winner->getAttributes();
                $winner->update(['winner_status_id' => 2, 'approved_by' => $request->user()->id, 'approved_at' => now()]);
                $winner->bet()->firstOrFail()->update(['result' => 'Win']);
                $this->log($request, $id, 'declare_winner', 'winner', $winner->id, $before, ['status' => 'Approved', 'prize_released' => $winner->prize_released]);
            }
        });
    }

    public function closeDue(): int
    {
        $ids = EndingGame::native()->where('betting', 'Enabled')->where('schedule', '<=', now()->addMinutes($this->cutoff()))->pluck('id');
        $closed = 0;
        foreach ($ids as $id) {
            $this->write((int) $id, function ($game) use (&$closed, $id): void {
                if ($game->betting !== 'Enabled') {
                    return;
                }
                $game->update(['betting' => 'Disabled', 'disabled_betting_at' => now()]);
                $this->log(null, (int) $id, 'automatic_betting_close', 'game', (int) $id, ['betting' => 'Enabled'], ['betting' => 'Disabled']);
                $closed++;
            });
        }
        $due = EndingGame::native()->whereNull('game_status_id')->where('schedule', '<=', now())->pluck('id');
        foreach ($due as $id) {
            $this->write((int) $id, function ($game) use ($id): void {
                if ($game->game_status_id !== null) {
                    return;
                }
                $game->update(['game_status_id' => 1]);
                $this->log(null, (int) $id, 'game_started', 'game', (int) $id, ['game_status_id' => null], ['game_status_id' => 1]);
            });
        }

        return $closed;
    }

    public function cutoff(): int
    {
        return max(0, (int) (EndingConfig::query()->where('config_name', 'Game Betting Disabled')->value('config_value') ?? 30));
    }

    /** @return array{team_a: int, team_b: int} */
    private function teams(Request $request): array
    {
        $a = EndingTeam::query()->where('id', $request->integer('team_a'))->where('is_active', 'Active')->first();
        $b = EndingTeam::query()->where('id', $request->integer('team_b'))->where('is_active', 'Active')->first();
        $this->check($a && $b && $a->id !== $b->id && $a->league_id === $b->league_id, 'team_b', 'Select two different active teams from the same league.');
        $this->check(EndingLeague::query()->where('id', $a->league_id)->where('is_active', 'Active')->exists(), 'league', 'Select an active league.');

        return ['team_a' => (int) $a->id, 'team_b' => (int) $b->id];
    }

    private function schedule(string $value): string
    {
        $date = CarbonImmutable::createFromFormat('!Y-m-d\TH:i', $value, 'Asia/Manila')->utc();
        $this->check($date->isFuture(), 'schedule', 'Choose a future schedule in Philippine time.');

        return $date->format('Y-m-d H:i:s');
    }

    /** @param array<int, array<string, mixed>> $prizes */
    private function cards(EndingGame $game, int $type, string $amount, int $quantity, array $prizes): int
    {
        $betAmount = $game->betAmounts()->create(['bet_type_id' => $type, 'amount' => $amount, 'created_at' => now()]);
        $amountId = $betAmount->id;
        foreach ($prizes as $prize) {
            $betAmount->prizes()->create([
                'bet_amount_id' => $amountId, 'quarter_id' => $prize['quarter_id'], 'is_cash' => $prize['is_cash'],
                'cash_prize' => $prize['is_cash'] === 'Cash' ? $prize['cash_prize'] : null,
                'other_prize' => $prize['is_cash'] === 'Cash' ? null : $prize['other_prize'],
            ]);
        }
        $this->insertCards($amountId, $quantity);

        return $amountId;
    }

    private function insertCards(int $amount, int $quantity): void
    {
        $cards = array_fill(0, $quantity, ['bet_amount_id' => $amount, 'betting' => 'Enabled', 'created_at' => now()]);
        foreach (array_chunk($cards, 100) as $rows) {
            EndingCard::query()->insert($rows);
        }
    }

    private function multiply(string $amount, string $factor): string
    {
        return number_format(round((float) $amount * (float) $factor, 2), 2, '.', '');
    }

    /** @return Builder<EndingGameResult> */
    private function results(int $game): Builder
    {
        return EndingGameResult::query()->where('game_id', $game);
    }

    /** @return Builder<EndingBet> */
    private function bets(int $game): Builder
    {
        return EndingBet::forGame($game);
    }

    /** @param array<string, mixed>|null $before
     * @param  array<string, mixed>|null  $after
     */
    private function log(?Request $request, int $game, string $action, string $subject, int $id, ?array $before, ?array $after): void
    {
        unset($before['legacy_checksum'], $before['legacy_dates'], $after['legacy_checksum'], $after['legacy_dates']);
        AdminEndingLog::query()->create([
            'game_id' => $game, 'user_id' => $request?->user()?->id, 'action' => $action, 'subject_type' => $subject,
            'subject_id' => $id, 'before' => $before,
            'after' => $after,
            'reason' => $request?->input('reason'), 'ip_address' => $request?->ip(), 'created_at' => now(),
        ]);
    }

    private function check(bool $condition, string $field, string $message): void
    {
        if (! $condition) {
            throw ValidationException::withMessages([$field => $message]);
        }
    }
}
