<?php

namespace App\Services\Players;

use App\Jobs\SendPlayerNotice;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PlayerWorkflow
{
    public function verify(Request $request, int $playerId, bool $reject): void
    {
        $this->requireReady();
        DB::transaction(function () use ($request, $playerId, $reject): void {
            $player = DB::table('players')->where('id', $playerId)->lockForUpdate()->firstOrFail();
            $profile = DB::table('player_profiles')->where('player_id', $playerId)->lockForUpdate()->firstOrFail();
            $this->check($profile->kyc_status === $request->string('expected')->toString(), 'This player was updated. Refresh the page and try again.');
            $this->check(in_array($profile->kyc_status, ['Files Submitted', 'Pending'], true), 'Only submitted or pending accounts can be evaluated.');
            $reason = $reject ? DB::table('admin_player_reject_reasons')->where('id', $request->integer('reject_reason_id'))->where('is_active', 'Yes')->value('reason') : null;
            $this->check(! $reject || $reason !== null, 'Select an active rejection reason.');
            $status = $reject ? 'Pending' : 'Verified';
            DB::table('player_profiles')->where('player_id', $playerId)->update(['kyc_status' => $status, 'updated_at' => now()]);
            if (! $reject) {
                DB::table('players')->where('id', $playerId)->update(['betting' => 'Enabled', 'cashin' => 'Enabled', 'cashout' => 'Enabled', 'updated_at' => now()]);
                DB::table('admin_player_verifications')->updateOrInsert(['player_id' => $playerId], ['verified_by' => $request->user()->id, 'verified_at' => now()]);
            }
            $details = $reject ? "Rejected player's account: ".$reason : "Verified player's account.";
            $this->audit($request, $playerId, $reject ? 'rejected_player' : 'verified_player',
                ['kyc_status' => $profile->kyc_status, 'betting' => $player->betting, 'cashin' => $player->cashin, 'cashout' => $player->cashout],
                ['kyc_status' => $status, 'betting' => $reject ? $player->betting : 'Enabled', 'cashin' => $reject ? $player->cashin : 'Enabled', 'cashout' => $reject ? $player->cashout : 'Enabled'], $details, $reason);
            if ($player->mobile_number) {
                SendPlayerNotice::dispatch($player->mobile_number, $reject
                    ? 'Ka Dribol, your Verification process failed: '.mb_strtoupper($reason).'. Kindly update your details to verify your account.'
                    : 'Good day Ka Dribol! Your GameX account has been successfully verified. Happy Ending!')->afterCommit();
            }
        });
    }

    public function setting(Request $request, int $playerId, string $setting): void
    {
        $this->requireReady();
        DB::transaction(function () use ($request, $playerId, $setting): void {
            $player = DB::table('players')->where('id', $playerId)->lockForUpdate()->firstOrFail();
            $column = $setting === 'account' ? 'status' : $setting;
            $this->check(($player->{$column} ?? 'Disabled') === $request->string('expected')->toString(), 'This setting was updated. Refresh the page and try again.');
            if ($setting !== 'account') {
                $this->check(DB::table('player_profiles')->where('player_id', $playerId)->value('kyc_status') === 'Verified', 'Only verified players can use this setting.');
            }
            $status = $setting === 'account' ? ($request->boolean('enabled') ? 'Active' : 'Inactive') : ($request->boolean('enabled') ? 'Enabled' : 'Disabled');
            if ($player->{$column} === $status) {
                return;
            }
            $update = [$column => $status, 'updated_at' => now()];
            if ($setting === 'account') {
                $update['session_version'] = (int) $player->session_version + 1;
            }
            DB::table('players')->where('id', $playerId)->update($update);
            $reason = $request->filled('reason') ? $request->string('reason')->trim()->toString() : null;
            $details = $setting === 'account' ? ($status === 'Active' ? 'Account activated' : 'Account deactivated') : ucfirst($setting).' '.$status;
            $this->audit($request, $playerId, 'player_'.$setting, [$column => $player->{$column}], [$column => $status], $details.($reason ? ': '.$reason : ''), $reason);
        });
    }

    public function fund(Request $request, int $playerId, int $fundId, string $action): void
    {
        $this->requireReady();
        DB::transaction(function () use ($request, $playerId, $fundId, $action): void {
            $player = DB::table('players')->where('id', $playerId)->lockForUpdate()->firstOrFail();
            $fund = DB::table('admin_player_fund_accounts')->where('player_id', $playerId)->where('id', $fundId)->lockForUpdate()->first();
            abort_unless($fund !== null, 404);
            $this->check($fund->is_verified === 'Pending' && $fund->is_active === 'Active', 'Only active, pending fund accounts can be evaluated.');
            $verified = $action === 'verify';
            $default = $verified && ! DB::table('admin_player_fund_accounts')->where('player_id', $playerId)->where('is_active', 'Active')->where('is_default', 'Yes')->where('is_verified', 'Verified')->exists();
            $after = ['is_verified' => $verified ? 'Verified' : 'Rejected', 'is_default' => $default ? 'Yes' : 'No'];
            DB::table('admin_player_fund_accounts')->where('id', $fundId)->update($after + ['updated_at' => now()]);
            $reason = $request->filled('reason') ? $request->string('reason')->trim()->toString() : null;
            $details = ($verified ? 'Verified' : 'Rejected')." player's withdrawal account";
            $this->audit($request, $playerId, $verified ? 'verified_player_fund_account' : 'rejected_player_fund_account',
                ['fund_id' => $fundId, 'is_verified' => $fund->is_verified, 'is_default' => $fund->is_default], ['fund_id' => $fundId] + $after,
                $details.($reason ? ': '.$reason : ''), $reason);
            if ($player->mobile_number) {
                SendPlayerNotice::dispatch($player->mobile_number, 'Your withdrawal account application has been '.($verified ? 'successfully verified.' : 'rejected: '.$reason.'.'))->afterCommit();
            }
        });
    }

    /** @param array<string, mixed> $before
     * @param  array<string, mixed>  $after
     */
    private function audit(Request $request, int $playerId, string $action, array $before, array $after, string $details, ?string $reason): void
    {
        DB::table('admin_player_audits')->insert([
            'player_id' => $playerId, 'user_id' => $request->user()->id, 'action' => $action,
            'before' => json_encode($before, JSON_THROW_ON_ERROR), 'after' => json_encode($after, JSON_THROW_ON_ERROR),
            'details' => $details, 'reason' => $reason, 'ip_address' => $request->ip(), 'created_at' => now(),
        ]);
    }

    private function check(bool $condition, string $message): void
    {
        if (! $condition) {
            throw ValidationException::withMessages(['player' => $message]);
        }
    }

    private function requireReady(): void
    {
        abort_unless(DB::table('admin_player_imports')->where('source', config('players.legacy_source'))->exists(), 503,
            'Player records are being prepared. Please try again shortly.');
    }
}
