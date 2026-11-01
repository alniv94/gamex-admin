<?php

namespace App\Services\OtsoPanalo;

use App\Services\Legacy\OtsoPanaloImport;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use stdClass;

class OtsoPanaloWorkflow
{
    public const QUARTERS = ['first', 'second', 'third', 'fourth'];

    public const PRIZES = ['bet_amount', 'jackpot_prize', 'second_prize', 'third_prize', 'fourth_prize', 'pool_percentage'];

    public const CANCELLATION_REASONS = ['cancelled', 'postponed_beyond_48_hours', 'official_results_cannot_be_determined', 'started_before_close_of_betting'];

    /** @template T
     * @param  ($gameId is null ? Closure(null): T : Closure(stdClass): T)  $operation
     * @return T
     */
    public function write(?int $gameId, Closure $operation): mixed
    {
        $lock = Cache::lock('otso:write', 7200);
        $this->check($lock->get(), 'An Otso Panalo update or import is in progress. Try again shortly.');
        try {
            $this->check(DB::table('admin_otso_panalo_imports')->where('source', config('otso.legacy_source'))->exists(), 'Import and verify Otso Panalo history before making changes.');

            return DB::transaction(function () use ($gameId, $operation) {
                $game = $gameId === null ? null : DB::table('otso_panalo_games')->where('id', $gameId)->lockForUpdate()->firstOrFail();

                return $operation($game);
            }, 3);
        } finally {
            $lock->release();
        }
    }

    public function config(Request $request, ?int $id = null): void
    {
        $this->write(null, function () use ($request, $id): void {
            $before = $id ? DB::table('otso_panalo_game_configs')->where('id', $id)->lockForUpdate()->firstOrFail() : null;
            $data = $request->only([...self::PRIZES, 'name', 'status']);
            if ($file = $request->file('logo')) {
                $data['logo'] = '/storage/'.$file->store('otso_panalo/game_configs', 'public');
            }
            $data['updated_at'] = now();
            $data['managed_at'] = now();
            if ($id) {
                DB::table('otso_panalo_game_configs')->where('id', $id)->update($data);
            } else {
                $id = DB::table('otso_panalo_game_configs')->insertGetId($data + ['created_at' => now()]);
            }
            if ($data['status'] === 'Active') {
                $others = DB::table('otso_panalo_game_configs')->where('id', '<>', $id)->where('status', 'Active')->get();
                foreach ($others as $other) {
                    $this->audit($request, null, 'deactivate_config', (array) $other, ['id' => $other->id, 'status' => 'Inactive']);
                }
                DB::table('otso_panalo_game_configs')->where('id', '<>', $id)->where('status', 'Active')->update(['status' => 'Inactive', 'updated_at' => now(), 'managed_at' => now()]);
            }
            $this->audit($request, null, $before ? 'update_config' : 'create_config', (array) $before, ['id' => $id] + $data);
        });
    }

    public function saveGame(Request $request, ?int $id = null): int
    {
        return $this->write($id, function (?stdClass $game) use ($request, $id): int {
            if ($game) {
                $this->check((int) $game->game_status_id === 1 && $game->betting === 'Disabled' && ! $this->hasResult($id), 'A started game cannot be edited.');
                $this->check((int) $game->ending_game_id === $request->integer('ending_game_id') || ! DB::table('otso_panalo_bets')->where('game_id', $id)->exists(), 'The matchup cannot change after bets have been recorded.');
            }
            $ending = DB::table('ending_games as e')->join('ending_teams as t', 't.id', '=', 'e.team_a')->join('ending_leagues as l', 'l.id', '=', 't.league_id')
                ->where('e.id', $request->integer('ending_game_id'))->where('l.league', 'NBA')->whereNull('e.game_status_id')->select('e.*')->lockForUpdate()->first();
            $this->check($ending !== null && $ending->schedule !== null, 'Select an available NBA ending game.');
            $this->check(! DB::table('otso_panalo_games')->where('ending_game_id', $ending->id)->when($id, fn ($q) => $q->where('id', '<>', $id))->exists(), 'This ending game already has an Otso Panalo game.');
            $config = DB::table('otso_panalo_game_configs')->where('status', 'Active')->orderByDesc('updated_at')->orderByDesc('id')->lockForUpdate()->first();
            $this->check($config !== null, 'Activate a game configuration first.');
            $deadline = $id ? CarbonImmutable::parse($request->string('betting_deadline')->toString(), 'Asia/Manila')->utc() : CarbonImmutable::parse($ending->schedule, 'UTC')->subMinutes(30);
            $this->check($deadline->isFuture() && $deadline->lt(CarbonImmutable::parse($ending->schedule, 'UTC')), 'The betting deadline must be in the future and before the game schedule.');
            $data = array_intersect_key((array) $config, array_flip(self::PRIZES));
            if ($game) {
                $data['jackpot_prize'] = $game->jackpot_prize;
            }
            $data += ['ending_game_id' => $ending->id, 'game_config_id' => $config->id, 'betting_deadline' => $deadline, 'updated_at' => now(), 'managed_at' => now()];
            if ($id) {
                DB::table('otso_panalo_games')->where('id', $id)->update($data);
            } else {
                $id = DB::table('otso_panalo_games')->insertGetId($data + ['created_at' => now()]);
            }
            $this->audit($request, $id, $game ? 'update_game' : 'create_game', (array) $game, ['id' => $id] + $data);

            return $id;
        });
    }

    public function betting(Request $request, int $id): void
    {
        $this->write($id, function (stdClass $game) use ($request, $id): void {
            $this->check(! in_array((int) $game->game_status_id, [3, 4], true) && ! $this->hasResult($id), 'Finished or cancelled games cannot change betting.');
            $enabled = $request->boolean('enabled');
            if ($enabled) {
                $this->check($game->betting_deadline !== null && CarbonImmutable::parse($game->betting_deadline, 'UTC')->isFuture(), 'The betting deadline has passed.');
            }
            $value = $enabled ? 'Enabled' : 'Disabled';
            if ($game->betting === $value) {
                return;
            }
            $prefix = $enabled ? 'enabled' : 'disabled';
            $data = ['betting' => $value, $prefix.'_betting_at' => now(), $prefix.'_betting_by' => $request->user()->id, 'updated_at' => now(), 'managed_at' => now()];
            DB::table('otso_panalo_games')->where('id', $id)->update($data);
            $this->audit($request, $id, 'switch_betting', ['betting' => $game->betting], $data);
        });
    }

    public function result(Request $request, int $id, bool $update = false): void
    {
        $this->write($id, function (stdClass $game) use ($request, $id, $update): void {
            $this->check((int) $game->game_status_id !== 4, 'Cancelled games cannot accept results.');
            $before = DB::table('otso_panalo_game_results')->where('game_id', $id)->lockForUpdate()->first();
            $this->check($update ? $before !== null && $before->status === 'For Approval' : $before === null, $update ? 'Only pending results may be edited.' : 'A result already exists for this game.');
            if ($update) {
                $this->check((int) $before->version === $request->integer('version'), 'The result changed. Refresh before continuing.');
            }
            $data = [];
            foreach (self::QUARTERS as $quarter) {
                $field = $quarter.'_quarter_result';
                $data[$field] = $request->input($field) ?: null;
            }
            $data += ['updated_at' => now(), 'managed_at' => now()];
            if ($update) {
                DB::table('otso_panalo_game_results')->where('id', $before->id)->update($data + ['version' => $before->version + 1]);
            } else {
                DB::table('otso_panalo_game_results')->insert($data + ['game_id' => $id, 'created_by' => $request->user()->id, 'created_at' => now()]);
                $gameData = ['game_status_id' => 3, 'betting' => 'Disabled', 'updated_at' => now(), 'managed_at' => now()];
                if ($game->betting === 'Enabled') {
                    $gameData += ['disabled_betting_at' => now(), 'disabled_betting_by' => $request->user()->id];
                }
                DB::table('otso_panalo_games')->where('id', $id)->update($gameData);
            }
            $this->audit($request, $id, $update ? 'update_result' : 'create_result', (array) $before, $data);
        });
    }

    public function approveResult(Request $request, int $id): void
    {
        $this->write($id, function (stdClass $game) use ($request, $id): void {
            $this->check((int) $game->game_status_id !== 4, 'Cancelled games cannot approve results.');
            $result = DB::table('otso_panalo_game_results')->where('game_id', $id)->lockForUpdate()->firstOrFail();
            $this->check($result->status === 'For Approval' && (int) $result->version === $request->integer('version'), 'The result changed or was already approved. Refresh before continuing.');
            $data = ['status' => 'Approved', 'approved_by' => $request->user()->id, 'approved_at' => now(), 'managed_at' => now()];
            DB::table('otso_panalo_game_results')->where('id', $result->id)->update($data);
            $this->audit($request, $id, 'approve_result', (array) $result, $data);
        });
    }

    public function generate(Request $request, int $id): int
    {
        return $this->write($id, function (stdClass $game) use ($request, $id): int {
            $result = DB::table('otso_panalo_game_results')->where('game_id', $id)->where('status', 'Approved')->first();
            $this->check((int) $game->game_status_id !== 4 && $result !== null, 'Approve the game result before generating winners.');
            if ($game->winners_generated_at !== null) {
                return 0;
            }
            $this->check(! DB::table('otso_panalo_bet_quarter_results')->where('game_id', $id)->exists()
                && ! DB::table('otso_panalo_winners as w')->join('otso_panalo_bets as b', 'b.id', '=', 'w.bet_id')->where('b.game_id', $id)->exists(), 'Historical outcomes already exist. Verify the imported history before generating winners.');
            $eligible = DB::table('otso_panalo_bets')->where('game_id', $id)->where('refunded', 'No');
            $jackpots = clone $eligible;
            foreach (self::QUARTERS as $quarter) {
                $value = trim((string) $result->{$quarter.'_quarter_result'});
                if ($value === '') {
                    $jackpots->whereRaw('1 = 0');
                } else {
                    $jackpots->whereRaw('TRIM('.$quarter.'_quarter_combination) = ?', [$value]);
                }
            }
            $jackpotCount = $jackpots->count();
            $sharedJackpot = $jackpotCount ? intdiv(self::cents((string) $game->jackpot_prize) * 2 + $jackpotCount, $jackpotCount * 2) : 0;
            $created = 0;
            $eligible->chunkById(250, function ($bets) use ($id, $game, $result, $sharedJackpot, &$created): void {
                $quarters = [];
                $winners = [];
                foreach ($bets as $bet) {
                    $matches = 0;
                    foreach (self::QUARTERS as $index => $quarter) {
                        $combination = trim((string) $bet->{$quarter.'_quarter_combination'}) ?: null;
                        $value = trim((string) $result->{$quarter.'_quarter_result'}) ?: null;
                        $won = $combination !== null && $value !== null && $combination === $value;
                        $matches += (int) $won;
                        $quarters[] = ['bet_id' => $bet->id, 'game_id' => $id, 'quarter_number' => $index + 1, 'bet_combination' => $combination, 'game_result' => $value, 'quarter_status' => $won ? 'Win' : 'Lose', 'created_at' => now()];
                    }
                    if ($matches > 0) {
                        $prize = match ($matches) {
                            4 => self::decimal($sharedJackpot), 3 => $game->second_prize, 2 => $game->third_prize, 1 => $game->fourth_prize
                        };
                        $winners[] = ['bet_id' => $bet->id, 'winner_type_id' => 5 - $matches, 'prize_amount' => $prize, 'created_at' => now()];
                    }
                }
                foreach (array_chunk($quarters, 100) as $rows) {
                    DB::table('otso_panalo_bet_quarter_results')->insert($rows);
                }
                if ($winners) {
                    DB::table('otso_panalo_winners')->insert($winners);
                }
                $created += count($winners);
            });
            DB::table('otso_panalo_games')->where('id', $id)->update(['winners_generated_at' => now(), 'managed_at' => now()]);
            $this->audit($request, $id, 'generate_winners', [], ['created' => $created, 'jackpot_winners' => $jackpotCount]);

            return $created;
        });
    }

    public function approveWinners(Request $request, int $id, ?int $winnerId): void
    {
        $this->write($id, function (stdClass $game) use ($request, $id, $winnerId): void {
            $this->check((int) $game->game_status_id !== 4 && DB::table('otso_panalo_game_results')->where('game_id', $id)->where('status', 'Approved')->exists(), 'Approve the game result first.');
            $query = DB::table('otso_panalo_winners as w')->join('otso_panalo_bets as b', 'b.id', '=', 'w.bet_id')->where('b.game_id', $id)->when($winnerId, fn ($q) => $q->where('w.id', $winnerId))->select('w.*', 'b.player_id', 'b.refunded')->orderBy('w.id')->lockForUpdate();
            if ($winnerId) {
                (clone $query)->firstOrFail();
            }
            $count = 0;
            foreach ($query->get() as $winner) {
                if ($winner->winner_status === 'Approved') {
                    continue;
                }
                $this->check($winner->refunded === 'No', 'Refunded tickets cannot receive prizes.');
                $data = ['winner_status' => 'Approved', 'approved_by' => $request->user()->id, 'approved_at' => now(), 'managed_at' => now()];
                if (in_array((int) $winner->winner_type_id, [2, 3, 4], true) && $winner->prize_released !== 'Yes') {
                    $this->credit($winner->player_id, (string) $winner->prize_amount, 'prize', (int) $winner->id, $id);
                    $data['prize_released'] = 'Yes';
                }
                DB::table('otso_panalo_winners')->where('id', $winner->id)->update($data);
                $count++;
            }
            if ($count) {
                $this->audit($request, $id, 'approve_winners', [], ['winner_id' => $winnerId, 'approved_count' => $count]);
            }
        });
    }

    public function cancel(Request $request, int $id): void
    {
        $this->write($id, function (stdClass $game) use ($request, $id): void {
            if ((int) $game->game_status_id === 4) {
                return;
            }
            $this->check((int) $game->game_status_id !== 3 && ! $this->hasResult($id) && $game->winners_generated_at === null && ! DB::table('otso_panalo_bet_quarter_results')->where('game_id', $id)->exists(), 'A game with results or generated outcomes cannot be cancelled.');
            $total = 0;
            DB::table('otso_panalo_bets')->where('game_id', $id)->where('refunded', 'No')->chunkById(250, function ($bets) use ($id, &$total): void {
                foreach ($bets as $bet) {
                    $this->credit($bet->player_id, (string) $bet->amount, 'refund', (int) $bet->id, $id);
                    $total += self::cents((string) $bet->amount);
                    DB::table('otso_panalo_bets')->where('id', $bet->id)->update(['refunded' => 'Yes', 'managed_at' => now()]);
                }
            });
            $data = ['game_status_id' => 4, 'betting' => 'Disabled', 'cancellation_reason' => $request->input('reason'), 'cancelled_by' => $request->user()->id, 'cancelled_at' => now(), 'updated_at' => now(), 'managed_at' => now()];
            if ($game->betting === 'Enabled') {
                $data += ['disabled_betting_at' => now(), 'disabled_betting_by' => $request->user()->id];
            }
            DB::table('otso_panalo_games')->where('id', $id)->update($data);
            $this->audit($request, $id, 'cancel_game', (array) $game, $data + ['refund_total' => self::decimal($total)]);
        });
    }

    public function importBets(Request $request, int $id, OtsoPanaloImport $import): int
    {
        return $this->write($id, function (stdClass $game) use ($request, $id, $import): int {
            $this->check($game->legacy_id !== null && ! $this->hasResult($id) && ! in_array((int) $game->game_status_id, [3, 4], true) && $game->winners_generated_at === null, 'Only unfinished legacy games without results can import additional bets.');
            $report = $import->scanBets((int) $game->legacy_id, true);
            $this->audit($request, $id, 'import_bets', [], $report);

            return $report['missing'];
        });
    }

    private function credit(?int $playerId, string $amount, string $kind, int $reference, int $gameId): void
    {
        $this->check($playerId !== null && DB::table('players')->where('id', $playerId)->lockForUpdate()->first() !== null, 'Resolve the ticket’s player mapping before settling this game.');
        $this->check(! DB::table('admin_otso_panalo_settlements')->where('kind', $kind)->where('reference_id', $reference)->exists(), 'This payment has already been recorded. Refresh and verify its status.');
        $wallet = DB::table('player_wallet')->where('player_id', $playerId)->lockForUpdate()->first();
        if ($wallet === null) {
            DB::table('player_wallet')->insert(['player_id' => $playerId, 'actual_amount' => '0.00']);
            $wallet = DB::table('player_wallet')->where('player_id', $playerId)->lockForUpdate()->firstOrFail();
        }
        $cents = self::cents($amount);
        $after = self::cents((string) $wallet->actual_amount) + $cents;
        $this->check($cents >= 0 && $after <= 9999999999, 'The payment exceeds the wallet balance limit.');
        $typeName = 'Otso Panalo '.($kind === 'prize' ? 'Prize' : 'Refund');
        $type = DB::table('player_wallet_transaction_types')->where('wallet_transaction_type', $typeName)->value('id');
        if (! $type) {
            $type = DB::table('player_wallet_transaction_types')->insertGetId([
                'wallet_transaction_type' => $typeName,
                'reference_table' => $kind === 'prize' ? 'otso_panalo_winners settled prizes' : 'otso_panalo_bets refunded wagers',
            ]);
        }
        DB::table('player_wallet')->where('id', $wallet->id)->update(['actual_amount' => self::decimal($after)]);
        $transaction = DB::table('player_wallet_transactions')->insertGetId(['player_wallet_id' => $wallet->id, 'player_id' => $playerId, 'transaction_type_id' => $type, 'reference_id' => $reference, 'description' => $typeName.' for game #'.$gameId.'.', 'amount' => self::decimal($cents), 'created_at' => now()]);
        DB::table('admin_otso_panalo_settlements')->insert(['kind' => $kind, 'reference_id' => $reference, 'player_id' => $playerId, 'wallet_transaction_id' => $transaction, 'amount' => self::decimal($cents), 'created_at' => now()]);
    }

    private function hasResult(int $gameId): bool
    {
        return DB::table('otso_panalo_game_results')->where('game_id', $gameId)->exists();
    }

    /** @param array<string, mixed> $before
     * @param  array<string, mixed>  $after
     */
    public function audit(Request $request, ?int $gameId, string $action, array $before, array $after): void
    {
        foreach (['legacy_payload', 'legacy_checksum', 'legacy_dates'] as $column) {
            unset($before[$column], $after[$column]);
        }
        DB::table('admin_otso_panalo_audits')->insert(['game_id' => $gameId, 'user_id' => $request->user()->id, 'action' => $action, 'before' => json_encode($before, JSON_THROW_ON_ERROR), 'after' => json_encode($after, JSON_THROW_ON_ERROR), 'ip_address' => $request->ip(), 'created_at' => now()]);
    }

    public static function cents(string $amount): int
    {
        if (! preg_match('/^\d{1,13}(?:\.\d{1,2})?$/D', $amount)) {
            throw ValidationException::withMessages(['action' => 'The stored payment amount is invalid.']);
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
