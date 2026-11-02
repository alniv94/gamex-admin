<?php

namespace App\Services\Agents;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AgentWorkflow
{
    /** @param array<string, mixed> $data */
    public function save(Request $request, ?int $id, array $data): int
    {
        $this->requireReady();

        return DB::transaction(function () use ($request, $id, $data): int {
            $before = $id ? (array) DB::table('admin_ending_agents')->where('id', $id)->lockForUpdate()->firstOrFail() : [];
            $account = array_intersect_key($data, array_flip(['outlet_id', 'username', 'is_active', 'type', 'commission']));
            if ($id) {
                DB::table('admin_ending_agents')->where('id', $id)->update($account);
            } else {
                $id = DB::table('admin_ending_agents')->insertGetId($account + ['password' => Hash::make($data['password']), 'created_at' => now()]);
            }
            $profile = array_intersect_key($data, array_flip(['first_name', 'middle_name', 'last_name', 'mobile_number', 'email_address', 'sub_type', 'page_name']));
            $oldProfile = (array) DB::table('admin_ending_agent_info')->where('agent_id', $id)->first();
            DB::table('admin_ending_agent_info')->updateOrInsert(['agent_id' => $id], $profile);
            $this->audit($request, $id, $before ? 'update_agent' : 'create_agent',
                array_intersect_key($before, $account) + array_intersect_key($oldProfile, $profile), $account + $profile);

            return $id;
        }, 3);
    }

    public function assign(Request $request, int $agent): void
    {
        $this->requireReady();
        DB::transaction(function () use ($request, $agent): void {
            DB::table('admin_ending_agents')->where('id', $agent)->lockForUpdate()->firstOrFail();
            $player = DB::table('players')->where('id', $request->integer('player_id'))->lockForUpdate()->firstOrFail();
            $this->check($player->status === 'Active', 'Only active players can be assigned.');
            $this->check(! DB::table('admin_ending_agent_players')->where('player_id', $player->id)->whereNull('detached_at')->exists(), 'This player is already assigned to an agent.');
            DB::table('admin_ending_agent_players')->insert(['agent_id' => $agent, 'player_id' => $player->id, 'created_at' => now()]);
            $this->audit($request, $agent, 'assign_player', [], ['player_id' => $player->id]);
        }, 3);
    }

    public function remove(Request $request, int $agent, int $link): void
    {
        $this->requireReady();
        DB::transaction(function () use ($request, $agent, $link): void {
            $row = DB::table('admin_ending_agent_players')->where('id', $link)->where('agent_id', $agent)->whereNull('detached_at')->lockForUpdate()->firstOrFail();
            DB::table('admin_ending_agent_players')->where('id', $link)->update(['detached_at' => now()]);
            $this->audit($request, $agent, 'remove_player', ['player_id' => $row->player_id, 'link_id' => $link], ['detached' => true]);
        }, 3);
    }

    public function resetPassword(Request $request, int $agent): void
    {
        $this->requireReady();
        DB::transaction(function () use ($request, $agent): void {
            $row = DB::table('admin_ending_agents')->where('id', $agent)->lockForUpdate()->firstOrFail();
            $this->check(! password_verify($request->input('password'), $row->password ?? ''), 'The new password must differ from the current password.');
            DB::table('admin_ending_agents')->where('id', $agent)->update(['password' => Hash::make($request->input('password'))]);
            $this->audit($request, $agent, 'reset_password', [], ['password_changed' => true]);
        }, 3);
    }

    public function fund(Request $request): void
    {
        $this->requireReady();
        DB::transaction(function () use ($request): void {
            $agent = DB::table('admin_ending_agents')->where('id', $request->integer('agent_id'))->lockForUpdate()->firstOrFail();
            $existing = DB::table('admin_ending_agent_cashin')->where('request_key', $request->input('request_key'))->first();
            if ($existing) {
                $this->check((int) $existing->agent_id === (int) $agent->id
                    && $this->cents((string) $existing->amount) === $this->cents((string) $request->input('amount'))
                    && (int) $existing->evaluated_by === (int) $request->user()->id && $existing->remarks === $request->input('remarks'), 'This submission key has already been used.');

                return;
            }
            $this->check($agent->is_active === 'Active', 'Only active agents can receive wallet funding.');
            $after = $this->credit((int) $agent->id, (string) $agent->wallet, (string) $request->input('amount'));
            $id = DB::table('admin_ending_agent_cashin')->insertGetId([
                'agent_id' => $agent->id, 'amount' => $request->input('amount'), 'type' => 'Manual', 'status' => 'Approved',
                'created_at' => now(), 'evaluated_at' => now(), 'evaluated_by' => $request->user()->id,
                'remarks' => $request->input('remarks'), 'request_key' => $request->input('request_key'),
            ]);
            $this->audit($request, (int) $agent->id, 'manual_cashin', ['wallet' => $agent->wallet], ['wallet' => $after, 'cashin_id' => $id, 'remarks' => $request->input('remarks')]);
        }, 3);
    }

    public function evaluate(Request $request, int $cashin, string $action): void
    {
        $this->requireReady();
        DB::transaction(function () use ($request, $cashin, $action): void {
            $row = DB::table('admin_ending_agent_cashin')->where('id', $cashin)->lockForUpdate()->firstOrFail();
            $this->check($row->status === 'Pending', 'This request was updated. Refresh the page before continuing.');
            $agent = DB::table('admin_ending_agents')->where('id', $row->agent_id)->lockForUpdate()->firstOrFail();
            $before = ['status' => $row->status, 'wallet' => $agent->wallet];
            $after = ['status' => $action === 'approve' ? 'Approved' : 'Disapproved', 'cashin_id' => $cashin];
            if ($action === 'approve') {
                $this->check($agent->is_active === 'Active', 'Only active agents can receive wallet funding.');
                $after['wallet'] = $this->credit((int) $agent->id, (string) $agent->wallet, (string) $row->amount);
            }
            DB::table('admin_ending_agent_cashin')->where('id', $cashin)->update([
                'status' => $after['status'], 'evaluated_by' => $request->user()->id, 'evaluated_at' => now(), 'remarks' => $request->input('remarks'),
            ]);
            $this->audit($request, (int) $agent->id, $action.'_cashin', $before, $after + ['remarks' => $request->input('remarks')]);
        }, 3);
    }

    private function credit(int $agent, string $balance, string $amount): string
    {
        $cents = $this->cents($amount);
        $this->check($cents > 0, 'The amount must be greater than zero.');
        $total = $this->cents($balance) + $cents;
        $this->check($total <= 9999999999, 'The resulting wallet balance exceeds its supported limit.');
        $decimal = intdiv($total, 100).'.'.str_pad((string) ($total % 100), 2, '0', STR_PAD_LEFT);
        DB::table('admin_ending_agents')->where('id', $agent)->update(['wallet' => $decimal]);

        return $decimal;
    }

    private function cents(string $amount): int
    {
        $this->check((bool) preg_match('/^\d{1,8}(?:\.\d{1,2})?$/D', $amount), 'The amount is invalid.');
        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '');

        return ((int) $whole * 100) + (int) str_pad($fraction, 2, '0');
    }

    /** @param array<string, mixed> $before
     * @param  array<string, mixed>  $after
     */
    private function audit(Request $request, int $agent, string $action, array $before, array $after): void
    {
        DB::table('admin_agent_audits')->insert([
            'agent_id' => $agent, 'user_id' => $request->user()->id, 'action' => $action,
            'before' => $before ? json_encode($before, JSON_THROW_ON_ERROR) : null, 'after' => json_encode($after, JSON_THROW_ON_ERROR),
            'ip_address' => $request->ip(), 'created_at' => now(),
        ]);
    }

    private function requireReady(): void
    {
        $this->check(DB::table('admin_agent_imports')->where('source', config('players.legacy_source'))->exists(), 'Import and verify agent records before making changes.');
    }

    private function check(bool $condition, string $message): void
    {
        if (! $condition) {
            throw ValidationException::withMessages(['action' => $message]);
        }
    }
}
