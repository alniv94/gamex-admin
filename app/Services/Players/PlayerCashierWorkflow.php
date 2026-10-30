<?php

namespace App\Services\Players;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PlayerCashierWorkflow
{
    public function evaluate(Request $request, string $kind, int $id, string $action): void
    {
        $this->requireReady();
        DB::transaction(function () use ($request, $kind, $id, $action): void {
            $row = DB::table('player_'.$kind)->where('id', $id)->lockForUpdate()->firstOrFail();
            $expected = in_array($action, ['verify', 'reject'], true) ? 'Pending' : 'Verified';
            $this->check($row->status === $expected && $row->status === $request->input('expected'), 'This request was updated. Refresh the page before continuing.');
            $this->check($row->player_id !== null && DB::table('players')->where('id', $row->player_id)->exists(), 'This request has no linked player. Resolve the identity mapping first.');
            $status = ['verify' => 'Verified', 'reject' => 'Rejected', 'approve' => 'Approved', 'disapprove' => 'Disapproved'][$action];
            $before = ['cashier_id' => $id, 'status' => $row->status];
            $after = ['cashier_id' => $id, 'status' => $status];
            if ($action === 'approve') {
                [$balanceBefore, $balanceAfter] = $this->transfer((int) $row->player_id, $kind, (string) $row->amount, $id);
                $before['wallet'] = $balanceBefore;
                $after['wallet'] = $balanceAfter;
            }
            DB::table('player_'.$kind)->where('id', $id)->update([
                'status' => $status, 'evaluated_by' => $request->user()->id,
                'evaluated_at' => now(), 'remarks' => $request->input('remarks'),
            ]);
            $this->audit($request, (int) $row->player_id, strtolower($status).'_'.$kind, $before, $after);
        }, 3);
    }

    public function manual(Request $request, string $kind): void
    {
        $this->requireReady();
        $approver = DB::table('users')->where('id', config('players.cashier_approval_admin_id'))->where('status', 'Active')->first();
        if (! $approver || ! password_verify($request->string('approval_password')->toString(), $approver->password)) {
            throw ValidationException::withMessages(['approval_password' => 'Incorrect approval password.']);
        }
        DB::transaction(function () use ($request, $kind): void {
            $player = DB::table('players')->where('id', $request->integer('player_id'))->lockForUpdate()->firstOrFail();
            $existing = DB::table('player_'.$kind)->where('request_key', $request->input('request_key'))->first();
            if ($existing) {
                $this->check((int) $existing->player_id === (int) $player->id
                    && $this->cents((string) $existing->amount) === $this->cents($request->string('amount')->toString())
                    && (int) $existing->evaluated_by === (int) $request->user()->id
                    && $existing->remarks === $request->input('remarks'), 'This submission key has already been used.');

                return;
            }
            $this->check($player->status === 'Active' && $player->{$kind} === 'Enabled'
                && DB::table('player_profiles')->where('player_id', $player->id)->value('kyc_status') === 'Verified',
                'Manual transactions require an active, verified player with this transaction enabled.');
            $id = DB::table('player_'.$kind)->insertGetId([
                'player_id' => $player->id, 'amount' => $request->input('amount'), 'type' => 'Manual',
                'status' => 'Approved', 'created_at' => now(), 'evaluated_at' => now(),
                'evaluated_by' => $request->user()->id, 'remarks' => $request->input('remarks'),
                'request_key' => $request->input('request_key'),
            ]);
            [$before, $after] = $this->transfer((int) $player->id, $kind, $request->string('amount')->toString(), $id);
            $this->audit($request, (int) $player->id, 'manual_'.$kind,
                ['cashier_id' => $id, 'wallet' => $before],
                ['cashier_id' => $id, 'status' => 'Approved', 'wallet' => $after]);
        }, 3);
    }

    /** @return array{string, string} */
    private function transfer(int $playerId, string $kind, string $amount, int $reference): array
    {
        $wallet = DB::table('player_wallet')->where('player_id', $playerId)->lockForUpdate()->first();
        $this->check($wallet !== null, 'The player wallet is unavailable.');
        $cents = $this->cents($amount);
        $this->check($cents > 0, 'The token amount must be greater than zero.');
        $before = $this->cents((string) $wallet->actual_amount);
        $after = $kind === 'cashin' ? $before + $cents : $before - $cents;
        $this->check($after >= 0, 'Player tokens are insufficient.');
        $this->check($after <= 9999999999, 'The resulting wallet balance exceeds its supported limit.');
        DB::table('player_wallet')->where('id', $wallet->id)->update(['actual_amount' => $this->decimal($after)]);
        DB::table('player_wallet_transactions')->insert([
            'player_wallet_id' => $wallet->id, 'player_id' => $playerId,
            'transaction_type_id' => $kind === 'cashin' ? 1 : 2,
            'reference_id' => $reference, 'description' => ($kind === 'cashin' ? 'Cash in ' : 'Cash out ').$this->decimal($cents).' has been approved.',
            'amount' => $this->decimal($cents), 'created_at' => now(),
        ]);

        return [$this->decimal($before), $this->decimal($after)];
    }

    private function cents(string $amount): int
    {
        $this->check((bool) preg_match('/^\d{1,8}(?:\.\d{1,2})?$/D', $amount), 'The token amount is invalid.');
        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '');

        return ((int) $whole * 100) + (int) str_pad($fraction, 2, '0');
    }

    private function decimal(int $cents): string
    {
        return intdiv($cents, 100).'.'.str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }

    /** @param array<string, mixed> $before
     * @param  array<string, mixed>  $after
     */
    private function audit(Request $request, int $playerId, string $action, array $before, array $after): void
    {
        DB::table('admin_player_audits')->insert([
            'player_id' => $playerId, 'user_id' => $request->user()->id, 'action' => $action,
            'details' => str_replace('_', ' ', ucfirst($action)).' request #'.$after['cashier_id'].'.',
            'before' => json_encode($before, JSON_THROW_ON_ERROR), 'after' => json_encode($after, JSON_THROW_ON_ERROR),
            'reason' => $request->input('remarks'), 'ip_address' => $request->ip(), 'created_at' => now(),
        ]);
    }

    private function requireReady(): void
    {
        $this->check(DB::table('admin_player_cashier_imports')->where('source', config('players.legacy_source'))->exists(),
            'Import and verify cashier history before processing transactions.');
    }

    private function check(bool $condition, string $message): void
    {
        if (! $condition) {
            throw ValidationException::withMessages(['action' => $message]);
        }
    }
}
