<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\Support\EndingFixtures;
use Tests\Support\PlayerFixtures;
use Tests\TestCase;

class AgentModuleTest extends TestCase
{
    use EndingFixtures, PlayerFixtures, RefreshDatabase;

    private function fixtures(): void
    {
        $this->playerFixtures();
        DB::table('admin_outlets')->insert(['id' => 1, 'outlet_name' => 'Test outlet']);
        DB::table('admin_ending_agents')->insert([
            ['id' => 1, 'username' => 'agent-one', 'wallet' => '50.25', 'password' => Hash::make('old-password')],
            ['id' => 2, 'username' => 'empty-agent', 'wallet' => '0.00', 'password' => 'private-hash'],
        ]);
        DB::table('admin_ending_agent_info')->insert([
            ['agent_id' => 1, 'first_name' => 'First', 'last_name' => 'Agent', 'mobile_number' => '9123456780', 'email_address' => 'one@example.test'],
            ['agent_id' => 2, 'first_name' => 'Empty', 'last_name' => 'Agent', 'mobile_number' => '9123456781', 'email_address' => 'two@example.test'],
        ]);
        DB::table('admin_agent_imports')->insert(['source' => config('players.legacy_source'), 'report' => '{}', 'verified_at' => now()]);
    }

    private function admin(array $permissions = []): void
    {
        $user = $this->endingAdmin($permissions);
        $user->givePermissionTo('Ending Agents');
    }

    private function payload(): array
    {
        return ['outlet_id' => 1, 'first_name' => 'New', 'middle_name' => '', 'last_name' => 'Agent', 'mobile_number' => '9123456782',
            'email_address' => 'new@example.test', 'username' => 'new-agent', 'password' => 'new-password', 'password_confirmation' => 'new-password',
            'commission' => '5.00', 'is_active' => 'Active', 'type' => 'Regular', 'sub_type' => 'Streamer'];
    }

    public function test_agent_pages_and_writes_require_authentication_and_permissions(): void
    {
        $this->get('/agents/list')->assertRedirect('/login');
        $this->endingAdmin([]);

        $this->get('/agents/list')->assertForbidden();
        $this->get('/agents/cashin')->assertForbidden();
        $this->getJson('/agents/players/search?search=Test')->assertForbidden();
        $this->post('/agents/accounts', [])->assertForbidden();
        $this->post('/agents/cashin/manual', [])->assertForbidden();
        $this->put('/agents/accounts/1/password', [])->assertForbidden();
        $this->delete('/agents/accounts/1/players/1')->assertForbidden();
        $this->assertDatabaseCount('admin_agent_audits', 0);
    }

    public function test_list_includes_agents_without_players_and_hides_credentials(): void
    {
        $this->fixtures();
        $this->admin();
        DB::table('admin_ending_agent_players')->insert([
            ['agent_id' => 1, 'player_id' => 9, 'legacy_player_id' => 73089],
            ['agent_id' => 1, 'player_id' => null, 'legacy_player_id' => 73090],
        ]);
        DB::table('player_profiles')->where('player_id', 9)->update(['kyc_status' => 'Verified']);

        $this->get('/agents/list?sort=player_count&direction=desc')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->component('agents/Index')->has('records.data', 2)->where('records.data.0.player_count', 2)->where('records.data.0.verified_count', 1)
            ->missing('records.data.0.password')->missing('records.data.0.legacy_checksum'));
        $this->get('/agents/list?search=empty-agent')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->has('records.data', 1)->where('records.data.0.player_count', 0));
    }

    public function test_invalid_sort_and_date_filters_are_rejected(): void
    {
        $this->fixtures();
        $this->admin();

        $this->getJson('/agents/list?sort=wallet%20desc;DROP&date_from=2026-11-02&date_to=2026-11-01')
            ->assertUnprocessable()->assertJsonValidationErrors(['sort', 'date_to']);
        $this->assertDatabaseCount('admin_ending_agents', 2);
    }

    public function test_create_requires_create_permission_then_hashes_password_and_starts_with_zero_wallet(): void
    {
        $this->fixtures();
        $this->admin();
        $this->post('/agents/accounts', $this->payload())->assertForbidden();
        auth()->user()->givePermissionTo('Ending Agents Create');

        $this->post('/agents/accounts', $this->payload() + ['wallet' => '999.00'])->assertRedirect();

        $this->assertDatabaseHas('admin_ending_agents', ['username' => 'new-agent', 'wallet' => '0.00', 'commission' => '5.00']);
        $this->assertDatabaseHas('admin_ending_agent_info', ['first_name' => 'New', 'mobile_number' => '9123456782']);
        $this->assertTrue(Hash::check('new-password', DB::table('admin_ending_agents')->where('username', 'new-agent')->value('password')));
        $this->assertDatabaseHas('admin_agent_audits', ['action' => 'create_agent']);
        $this->assertStringNotContainsString('new-password', DB::table('admin_agent_audits')->value('after'));
    }

    public function test_legacy_username_conflicts_are_flagged_and_do_not_block_unrelated_profile_edits(): void
    {
        $this->fixtures();
        $this->admin();
        DB::table('admin_ending_agents')->where('id', 2)->update(['username' => 'agent-one']);
        $data = $this->payload();
        unset($data['password'], $data['password_confirmation']);
        $data['username'] = 'agent-one';

        $this->get('/agents/accounts/1')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->component('agents/Account')->where('account.username_conflicts', 1)->missing('account.password'));
        $this->put('/agents/accounts/1', $data)->assertSessionHasNoErrors();

        $this->assertDatabaseHas('admin_ending_agent_info', ['agent_id' => 1, 'first_name' => 'New']);
        $this->assertDatabaseHas('admin_ending_agents', ['id' => 2, 'username' => 'agent-one']);
    }

    public function test_account_edit_cannot_overwrite_wallet_and_rejects_duplicate_identity(): void
    {
        $this->fixtures();
        $this->admin();
        $data = $this->payload();
        unset($data['password'], $data['password_confirmation']);

        $this->put('/agents/accounts/1', $data + ['wallet' => '999.99'])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('admin_ending_agents', ['id' => 1, 'wallet' => '50.25', 'username' => 'new-agent']);
        $data['username'] = 'empty-agent';
        $data['mobile_number'] = '9123456781';
        $this->put('/agents/accounts/1', $data)->assertSessionHasErrors(['username', 'mobile_number']);
        $this->assertDatabaseCount('admin_agent_audits', 1);
    }

    public function test_write_is_blocked_until_import_is_verified(): void
    {
        $this->fixtures();
        $this->admin([28]);
        DB::table('admin_agent_imports')->delete();

        $this->post('/agents/cashin/manual', ['agent_id' => 1, 'amount' => '1.00', 'remarks' => 'Funding', 'request_key' => (string) Str::uuid()])->assertSessionHasErrors('action');

        $this->assertDatabaseHas('admin_ending_agents', ['id' => 1, 'wallet' => '50.25']);
        $this->assertDatabaseCount('admin_ending_agent_cashin', 0);
    }

    public function test_lookup_and_assignment_exclude_already_assigned_or_inactive_players(): void
    {
        $this->fixtures();
        $this->admin();
        DB::table('players')->where('id', 11)->update(['status' => 'Inactive']);

        $this->post('/agents/accounts/1/players', ['player_id' => 9])->assertSessionHasNoErrors();
        $this->getJson('/agents/players/search?search=Test')->assertOk()->assertJsonCount(1, 'records')->assertJsonPath('records.0.id', 10);
        $this->post('/agents/accounts/2/players', ['player_id' => 9])->assertSessionHasErrors('action');
        $this->post('/agents/accounts/2/players', ['player_id' => 11])->assertSessionHasErrors('action');

        $this->assertDatabaseCount('admin_ending_agent_players', 1);
        $this->assertDatabaseHas('admin_ending_agent_players', ['agent_id' => 1, 'player_id' => 9]);
    }

    public function test_removal_is_scoped_preserves_history_and_allows_reassignment(): void
    {
        $this->fixtures();
        $this->admin();
        $link = DB::table('admin_ending_agent_players')->insertGetId(['agent_id' => 1, 'player_id' => 9, 'legacy_player_id' => 73089]);

        $this->delete('/agents/accounts/2/players/'.$link)->assertNotFound();
        $this->delete('/agents/accounts/1/players/'.$link)->assertSessionHasNoErrors();
        $this->post('/agents/accounts/2/players', ['player_id' => 9])->assertSessionHasNoErrors();

        $this->assertDatabaseCount('admin_ending_agent_players', 2);
        $this->assertDatabaseHas('admin_ending_agent_players', ['agent_id' => 2, 'player_id' => 9, 'detached_at' => null]);
        $this->assertDatabaseHas('player_wallet', ['player_id' => 9, 'actual_amount' => '123.45']);
    }

    public function test_password_reset_requires_confirmation_and_does_not_log_secrets(): void
    {
        $this->fixtures();
        $this->admin();

        $this->put('/agents/accounts/1/password', ['password' => 'new-password', 'password_confirmation' => 'different'])->assertSessionHasErrors('password');
        $this->put('/agents/accounts/1/password', ['password' => 'old-password', 'password_confirmation' => 'old-password'])->assertSessionHasErrors('action');
        $this->put('/agents/accounts/1/password', ['password' => 'new-password', 'password_confirmation' => 'new-password'])->assertSessionHasNoErrors();

        $this->assertTrue(Hash::check('new-password', DB::table('admin_ending_agents')->where('id', 1)->value('password')));
        $this->assertDatabaseHas('admin_agent_audits', ['action' => 'reset_password', 'after' => '{"password_changed":true}']);
    }

    public function test_manual_funding_requires_permission_is_exact_and_does_not_replay_on_retry(): void
    {
        $this->fixtures();
        $this->admin();
        $data = ['agent_id' => 1, 'amount' => '0.10', 'remarks' => 'Test funding', 'request_key' => (string) Str::uuid()];
        $this->post('/agents/cashin/manual', $data)->assertForbidden();
        $this->admin([28]);

        $this->post('/agents/cashin/manual', $data)->assertSessionHasNoErrors();
        $this->post('/agents/cashin/manual', $data)->assertSessionHasNoErrors();
        $data['amount'] = '1.00';
        $this->post('/agents/cashin/manual', $data)->assertSessionHasErrors('action');

        $this->assertDatabaseHas('admin_ending_agents', ['id' => 1, 'wallet' => '50.35']);
        $this->assertDatabaseCount('admin_ending_agent_cashin', 1);
        $this->assertDatabaseCount('admin_agent_audits', 1);
        $this->assertDatabaseHas('player_wallet', ['player_id' => 9, 'actual_amount' => '123.45']);
    }

    public function test_negative_funding_inactive_agent_and_wallet_overflow_cannot_credit(): void
    {
        $this->fixtures();
        $this->admin([28]);
        $data = ['agent_id' => 1, 'amount' => '-1.00', 'remarks' => 'Test funding', 'request_key' => (string) Str::uuid()];

        $this->post('/agents/cashin/manual', $data)->assertSessionHasErrors('amount');
        DB::table('admin_ending_agents')->where('id', 1)->update(['is_active' => 'Inactive']);
        $data['amount'] = '1.00';
        $this->post('/agents/cashin/manual', $data)->assertSessionHasErrors('action');
        DB::table('admin_ending_agents')->where('id', 1)->update(['is_active' => 'Active', 'wallet' => '99999999.99']);
        $this->post('/agents/cashin/manual', $data)->assertSessionHasErrors('action');

        $this->assertDatabaseHas('admin_ending_agents', ['id' => 1, 'wallet' => '99999999.99']);
        $this->assertDatabaseCount('admin_ending_agent_cashin', 0);
        $this->assertDatabaseCount('admin_agent_audits', 0);
    }

    public function test_approval_credits_agent_once_and_never_credits_player_with_same_request_id(): void
    {
        $this->fixtures();
        $this->admin([26]);
        DB::table('admin_ending_agent_cashin')->insert(['id' => 1, 'agent_id' => 1, 'amount' => '10.15']);
        DB::table('player_cashin')->insert(['id' => 1, 'player_id' => 9, 'amount' => '999.00', 'status' => 'Pending']);

        $this->post('/agents/cashin/1/approve')->assertSessionHasNoErrors();
        $this->post('/agents/cashin/1/approve')->assertSessionHasErrors('action');

        $this->assertDatabaseHas('admin_ending_agents', ['id' => 1, 'wallet' => '60.40']);
        $this->assertDatabaseHas('admin_ending_agent_cashin', ['id' => 1, 'status' => 'Approved']);
        $this->assertDatabaseHas('player_cashin', ['id' => 1, 'status' => 'Pending']);
        $this->assertDatabaseHas('player_wallet', ['player_id' => 9, 'actual_amount' => '123.45']);
        $this->assertDatabaseCount('admin_agent_audits', 1);
    }

    public function test_disapproval_requires_remarks_and_retains_wallet_balance(): void
    {
        $this->fixtures();
        $this->admin([27]);
        DB::table('admin_ending_agent_cashin')->insert(['id' => 1, 'agent_id' => 1, 'amount' => '10.15']);

        $this->post('/agents/cashin/1/disapprove')->assertSessionHasErrors('remarks');
        $this->post('/agents/cashin/1/disapprove', ['remarks' => 'Invalid receipt'])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('admin_ending_agent_cashin', ['id' => 1, 'status' => 'Disapproved', 'remarks' => 'Invalid receipt']);
        $this->assertDatabaseHas('admin_ending_agents', ['id' => 1, 'wallet' => '50.25']);
    }

    public function test_cashins_use_one_production_list_with_agent_linked_requests_and_manila_dates(): void
    {
        $this->fixtures();
        $this->admin();
        DB::table('admin_ending_agent_players')->insert(['agent_id' => 1, 'player_id' => 9]);
        DB::table('player_cashin')->insert([
            ['id' => 1, 'player_id' => 9, 'amount' => '12.34', 'status' => 'Approved', 'created_at' => '2026-11-01 15:59:59', 'evaluated_at' => '2026-11-01 16:00:00'],
            ['id' => 2, 'player_id' => 11, 'amount' => '999.00', 'status' => 'Approved', 'created_at' => '2026-11-01 16:00:00', 'evaluated_at' => '2026-11-01 16:00:00'],
        ]);
        DB::table('admin_ending_agent_cashin')->insert(['id' => 1, 'agent_id' => 1, 'amount' => '5.50', 'created_at' => '2026-11-01 16:00:00']);

        $this->get('/agents/cashin?date_from=2026-11-02&date_to=2026-11-02&status=Approved')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->component('agents/CashIn')->has('records.data', 1)->where('records.data.0.amount', 12.34)->where('records.data.0.player_id', 9)->missing('agents.0.password'));
        $this->get('/agents/cashin?scope=wallet&date_from=2026-11-02&date_to=2026-11-02&status=Approved')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->has('records.data', 1)->where('records.data.0.amount', 12.34)->where('records.data.0.player_id', 9)->missing('filters.scope'));
    }

    public function test_quarantined_player_cashins_remain_visible_under_the_legacy_agent_without_linking_another_player(): void
    {
        $this->fixtures();
        $this->admin();
        DB::table('admin_ending_agent_players')->insert(['agent_id' => 1, 'player_id' => null, 'legacy_player_id' => 73090]);
        DB::table('player_cashin')->insert(['id' => 1, 'player_id' => null, 'legacy_player_id' => 73090, 'amount' => '27.89', 'created_at' => '2026-11-01 16:00:00']);

        $this->get('/agents/cashin?date_from=2026-11-02&date_to=2026-11-02')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->has('records.data', 1)->where('records.data.0.agent_id', 1)->where('records.data.0.player_id', null)->where('records.data.0.player_name', null));

        $this->assertDatabaseHas('player_wallet', ['player_id' => 9, 'actual_amount' => '123.45']);
    }

    public function test_all_dates_includes_historical_cashins_while_the_initial_view_defaults_to_today(): void
    {
        $this->fixtures();
        $this->admin();
        $this->travelTo('2026-11-02 04:00:00');
        DB::table('admin_ending_agent_players')->insert(['agent_id' => 1, 'player_id' => 9]);
        DB::table('player_cashin')->insert(['id' => 1, 'player_id' => 9, 'amount' => '12.34', 'created_at' => '2025-09-24 01:25:09']);

        $this->get('/agents/cashin')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('filters.date_from', '2026-11-02')->where('filters.date_to', '2026-11-02')->has('records.data', 0));
        $this->get('/agents/cashin?date_from=&date_to=&agent_id=1')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('filters.date_from', '')->where('filters.date_to', '')->where('filters.agent_id', 1)->has('records.data', 1)->where('records.data.0.id', 1));
    }
}
