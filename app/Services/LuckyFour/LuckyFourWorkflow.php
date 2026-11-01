<?php

namespace App\Services\LuckyFour;

use App\Models\EndingGame;
use App\Services\Ending\EndingWorkflow;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LuckyFourWorkflow
{
    public const SETTINGS = ['jackpot_prize', 'three_digits_prize', 'initial_amount', 'percentage_deduction', 'bet_amount'];

    public function __construct(private EndingWorkflow $ending, private LuckyFourQueries $queries) {}

    /** @template T
     * @param  Closure(?EndingGame): T  $operation
     * @return T
     */
    private function write(?int $game, Closure $operation): mixed
    {
        $this->check($this->queries->ready(), 'Import and verify Lucky Four history before making changes.');

        return $this->ending->write($game, $operation);
    }

    public function toggle(Request $request, int $id): void
    {
        $this->write($id, function (?EndingGame $game) use ($request, $id): void {
            abort_unless($game !== null, 404);
            $status = $request->boolean('enabled') ? 'Yes' : 'No';
            if ($game->is_lucky_four === $status) {
                return;
            }
            $this->check(! $game->game_status_id && $game->schedule?->isFuture() && ! $game->results()->exists(), 'Lucky Four can only change before the game starts.');
            $this->check(! DB::table('lucky_four_bets')->where('game_id', $id)->exists(), 'Lucky Four cannot change after bets have been placed.');
            if ($status === 'Yes') {
                $settings = DB::table('lucky_four_prizes_settings')->where('id', 1)->first();
                $this->check($settings !== null, 'Save Lucky Four prize settings before enabling a game.');
                $values = [];
                foreach (self::SETTINGS as $field) {
                    $this->check($settings->{$field} !== null, 'Lucky Four prize settings are incomplete.');
                    $values[$field] = self::decimal(self::cents((string) $settings->{$field}));
                }
                $this->check(self::cents($values['bet_amount']) > 0 && self::cents($values['percentage_deduction']) <= 10000, 'Review the wager and pool percentage in Lucky Four prize settings.');
                $values['initial_amount'] = $values['jackpot_prize'];
                DB::table('lucky_four_prizes')->updateOrInsert(['game_id' => $id], $values + ['amount_added' => '0.00', 'managed_at' => now()]);
            }
            $before = ['is_lucky_four' => $game->is_lucky_four];
            $game->forceFill(['is_lucky_four' => $status])->save();
            $this->audit($request, $id, 'set_is_lucky_four', $before, ['is_lucky_four' => $status]);
        });
    }

    /** @param array<string, mixed> $values */
    public function settings(Request $request, array $values): void
    {
        $this->write(null, function () use ($request, $values): void {
            $before = DB::table('lucky_four_prizes_settings')->where('id', 1)->first();
            $data = [];
            foreach (self::SETTINGS as $field) {
                $data[$field] = self::decimal(self::cents((string) $values[$field]));
            }
            DB::table('lucky_four_prizes_settings')->updateOrInsert(['id' => 1], $data + ['managed_at' => now()]);
            DB::table('lucky_four_prizes_setting_logs')->insert($data + ['prizes_setting_id' => 1, 'created_at' => now()]);
            $this->audit($request, null, 'update_prizes_setting', (array) $before, $data);
        });
    }

    public function refresh(Request $request, int $id): void
    {
        $this->write($id, function (?EndingGame $game) use ($request, $id): void {
            abort_unless($game !== null, 404);
            $this->check($game->is_lucky_four === 'Yes', 'Lucky Four is disabled for this game.');
            $this->check($game->betting === 'Disabled', 'Close Ending betting before refreshing the jackpot.');
            $this->check(! $this->activeWinners($id), 'Unverify winners before changing the jackpot. Approved prizes lock the pool.');
            $prize = DB::table('lucky_four_prizes')->where('game_id', $id)->lockForUpdate()->first();
            $this->check($prize !== null, 'This game has no Lucky Four prize snapshot.');
            $pending = DB::table('lucky_four_bets')->where('game_id', $id)->where('is_added', 'No');
            $total = 0;
            $count = 0;
            foreach ((clone $pending)->select(['id', 'amount'])->lockForUpdate()->get() as $bet) {
                $this->check($bet->amount !== null, 'A bet has an incomplete amount. Review it before refreshing the jackpot.');
                $total += self::cents((string) $bet->amount);
                $count++;
            }
            if ($count === 0) {
                return;
            }
            $percentage = self::cents((string) $prize->percentage_deduction);
            $this->check($percentage <= 10000, 'The jackpot pool percentage must be between 0 and 100.');
            $added = intdiv($total * $percentage + 5000, 10000);
            $jackpot = self::cents((string) $prize->jackpot_prize) + $added;
            $accumulated = self::cents((string) $prize->amount_added) + $added;
            $this->check($jackpot <= 9999999999 && $accumulated <= 9999999999, 'The jackpot exceeds the supported amount.');
            $changes = ['jackpot_prize' => self::decimal($jackpot), 'amount_added' => self::decimal($accumulated), 'managed_at' => now()];
            DB::table('lucky_four_prizes')->where('id', $prize->id)->update($changes);
            $pending->update(['is_added' => 'Yes', 'managed_at' => now()]);
            $this->audit($request, $id, 'refresh_jackpot_prize', (array) $prize, $changes + ['bets_added' => $count]);
        });
    }

    public function winner(Request $request, int $gameId, string $action): void
    {
        $this->write($gameId, function (?EndingGame $game) use ($request, $gameId, $action): void {
            abort_unless($game !== null, 404);
            $betId = $request->integer('ending_bet_id');
            $bet = $this->queries->bets($gameId)->where('l.ending_bet_id', $betId)->first();
            abort_unless($bet !== null, 404);
            $winner = DB::table('lucky_four_winners')->where('ending_bet_id', $betId)->lockForUpdate()->first();
            if ($winner && ($winner->winner_status === 'Approved' || $winner->prize_released === 'Yes')) {
                $this->check($action === 'approve' && $winner->winner_status === 'Approved' && $winner->prize_released === 'Yes', 'Released or approved prizes cannot be changed.');

                return;
            }
            if ($action === 'unverify') {
                $this->check($winner !== null && $winner->winner_status === 'Verified', 'Only a verified winner can be unverified.');
                DB::table('lucky_four_winners')->where('id', $winner->id)->update(['winner_status' => 'Unverified', 'managed_at' => now()]);
                $this->audit($request, $gameId, 'unverify_winner', (array) $winner, ['ending_bet_id' => $betId, 'winner_status' => 'Unverified']);

                return;
            }
            $this->check($game->is_lucky_four === 'Yes' && (int) $game->game_status_id === 5 && $game->betting === 'Disabled', 'Finish the Ending game and close betting before processing Lucky Four winners.');
            $this->check($this->queries->result($gameId) !== null, 'Four unique quarter results are required.');
            $this->check(! DB::table('lucky_four_bets')->where('game_id', $gameId)->where('is_added', 'No')->exists(), 'Refresh the jackpot before verifying winners.');
            $candidate = $this->queries->winners($gameId)->where('l.ending_bet_id', $betId)->first();
            $this->check($candidate !== null && $candidate->tier !== null && $candidate->calculated_prize !== null, 'This ticket does not match a complete winning result and prize.');
            $directTicket = (int) $bet->game_type_id === 3 && (int) $bet->card_id === 0;
            $this->check($bet->refunded === 'No' && ($directTicket || $bet->card_refunded === 'No'), 'Refunded or incomplete tickets cannot receive a Lucky Four prize.');
            $this->check($bet->player_id !== null && DB::table('players')->where('id', $bet->player_id)->lockForUpdate()->exists(), 'Resolve the ticket player identity before processing its prize.');
            $prize = self::decimal(self::cents((string) $candidate->calculated_prize));
            if ($action === 'verify') {
                if ($winner?->winner_status === 'Verified') {
                    return;
                }
                DB::table('lucky_four_winners')->updateOrInsert(['ending_bet_id' => $betId], ['prize_amount' => $prize, 'winner_status' => 'Verified', 'prize_released' => 'No', 'verified_by' => $request->user()->id, 'created_at' => $winner ? $winner->created_at : now(), 'managed_at' => now()]);
                $this->audit($request, $gameId, 'verify_winner', (array) $winner, ['ending_bet_id' => $betId, 'prize_amount' => $prize, 'tier' => $candidate->tier]);

                return;
            }
            $this->check($winner !== null && $winner->winner_status === 'Verified', 'Verify the winner before approval.');
            $this->check($winner->prize_amount !== null && self::cents((string) $winner->prize_amount) === self::cents($prize), 'The calculated prize changed. Unverify and review this winner before approval.');
            $this->credit((int) $bet->player_id, $prize, (int) $winner->id, $gameId);
            $changes = ['winner_status' => 'Approved', 'prize_released' => 'Yes', 'approved_by' => $request->user()->id, 'approved_at' => now(), 'managed_at' => now()];
            DB::table('lucky_four_winners')->where('id', $winner->id)->update($changes);
            DB::table('ending_bets')->where('id', $betId)->update(['result' => 'Win']);
            $this->audit($request, $gameId, 'approve_winner', (array) $winner, $changes + ['ending_bet_id' => $betId, 'prize_amount' => $prize]);
        });
    }

    private function credit(int $playerId, string $amount, int $winnerId, int $gameId): void
    {
        $this->check(! DB::table('admin_lucky_four_settlements')->where('winner_id', $winnerId)->exists(), 'This prize already has a payment record. Review its status.');
        $wallet = DB::table('player_wallet')->where('player_id', $playerId)->lockForUpdate()->first();
        if (! $wallet) {
            DB::table('player_wallet')->insert(['player_id' => $playerId, 'actual_amount' => '0.00']);
            $wallet = DB::table('player_wallet')->where('player_id', $playerId)->lockForUpdate()->firstOrFail();
        }
        $after = self::cents((string) $wallet->actual_amount) + self::cents($amount);
        $this->check($after <= 9999999999, 'The prize exceeds the wallet balance limit.');
        $type = DB::table('player_wallet_transaction_types')->where('wallet_transaction_type', 'Lucky Four Prize')->value('id');
        if (! $type) {
            $type = DB::table('player_wallet_transaction_types')->insertGetId(['wallet_transaction_type' => 'Lucky Four Prize', 'reference_table' => 'lucky_four_winners settled prizes']);
        }
        DB::table('player_wallet')->where('id', $wallet->id)->update(['actual_amount' => self::decimal($after)]);
        $transaction = DB::table('player_wallet_transactions')->insertGetId(['player_wallet_id' => $wallet->id, 'player_id' => $playerId, 'transaction_type_id' => $type, 'reference_id' => $winnerId, 'description' => 'Lucky Four prize for game #'.$gameId.'.', 'amount' => $amount, 'created_at' => now()]);
        DB::table('admin_lucky_four_settlements')->insert(['winner_id' => $winnerId, 'player_id' => $playerId, 'wallet_transaction_id' => $transaction, 'amount' => $amount, 'created_at' => now()]);
    }

    private function activeWinners(int $gameId): bool
    {
        return DB::table('lucky_four_winners as w')->join('lucky_four_bets as b', 'b.ending_bet_id', '=', 'w.ending_bet_id')
            ->where('b.game_id', $gameId)->where(fn ($q) => $q->whereIn('w.winner_status', ['Verified', 'Approved'])->orWhere('w.prize_released', 'Yes'))->exists();
    }

    /** @param array<string, mixed> $before
     * @param  array<string, mixed>  $after
     */
    private function audit(Request $request, ?int $game, string $action, array $before, array $after): void
    {
        foreach (['legacy_payload', 'legacy_checksum', 'legacy_dates', 'managed_at'] as $column) {
            unset($before[$column], $after[$column]);
        }
        DB::table('admin_lucky_four_audits')->insert(['game_id' => $game, 'user_id' => $request->user()->id, 'action' => $action,
            'before' => json_encode($before, JSON_THROW_ON_ERROR), 'after' => json_encode($after, JSON_THROW_ON_ERROR), 'ip_address' => $request->ip(), 'created_at' => now()]);
    }

    public static function cents(string $amount): int
    {
        if (! preg_match('/^\d{1,8}(?:\.\d{1,2})?$/D', $amount)) {
            throw ValidationException::withMessages(['action' => 'The stored Lucky Four amount is invalid.']);
        }
        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '');

        return (int) $whole * 100 + (int) str_pad($fraction, 2, '0');
    }

    public static function decimal(int $cents): string
    {
        return intdiv($cents, 100).'.'.str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }

    private function check(bool $condition, string $message): void
    {
        if (! $condition) {
            throw ValidationException::withMessages(['action' => $message]);
        }
    }
}
