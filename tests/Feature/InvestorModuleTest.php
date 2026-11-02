<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia;
use Tests\Support\EndingFixtures;
use Tests\Support\PlayerFixtures;
use Tests\TestCase;

class InvestorModuleTest extends TestCase
{
    use EndingFixtures, PlayerFixtures, RefreshDatabase;

    private function fixtures(): void
    {
        DB::table('admin_outlets')->insert(['id' => 1, 'outlet_name' => 'Test outlet']);
        DB::table('admin_investors')->insert([
            ['id' => 1, 'outlet_id' => 1, 'investor_type_id' => 1, 'first_name' => 'Master', 'last_name' => 'Agent', 'territory' => 'TEST', 'mobile_number' => '9123456789', 'wallet' => '50.25', 'password' => 'private-hash', 'link_type' => 'New'],
            ['id' => 2, 'outlet_id' => 1, 'investor_type_id' => 2, 'first_name' => 'Sales', 'last_name' => 'Manager', 'territory' => 'TEST', 'mobile_number' => '9123456790', 'wallet' => '0', 'password' => 'private-hash', 'link_type' => 'New'],
            ['id' => 3, 'outlet_id' => 1, 'investor_type_id' => 1, 'first_name' => 'Other', 'last_name' => 'Master', 'territory' => 'OTHER', 'mobile_number' => '9123456791', 'wallet' => '0', 'password' => 'private-hash', 'link_type' => 'Old'],
        ]);
        DB::table('admin_investor_sub_agents')->insert(['investor_id' => 1, 'investor_agent_id' => 2]);
        DB::table('admin_investor_imports')->insert(['source' => config('players.legacy_source'), 'report' => '{}', 'verified_at' => now()]);
    }

    private function admin(array $permissions = ['Investors']): void
    {
        $user = $this->endingAdmin([]);
        $user->givePermissionTo($permissions);
    }

    private function commission(array $data = [], bool $referral = false): int
    {
        return DB::table($referral ? 'admin_investor_sales_representative_commissions' : 'admin_investor_commissions')->insertGetId($data + [
            'investor_id' => 2, 'commission_percent' => '1.00', 'commission_amount' => '0.10', 'is_settled' => 'No', 'is_paid' => 'No',
            'bet_created_at' => '2026-11-01 16:00:00',
        ]);
    }

    private function payment(): array
    {
        return ['mode_of_payment' => 'Bank Transfer', 'reference' => 'TEST-PAYMENT', 'remarks' => 'Test settlement'];
    }

    public function test_all_six_pages_and_exports_require_authentication_and_permissions(): void
    {
        $this->get('/investors/list')->assertRedirect('/login');
        $this->admin([]);

        foreach (['list', 'payouts', 'commissions', 'master-agents', 'referral-links', 'special-commissions'] as $section) {
            $this->get('/investors/'.$section)->assertForbidden();
            $this->get('/investors/'.$section.'/export')->assertForbidden();
        }
        $this->post('/investors/settlements/payouts/1', $this->payment())->assertForbidden();
    }

    public function test_lists_hierarchy_and_referrals_hide_credentials_and_show_legacy_links(): void
    {
        $this->fixtures();
        $this->playerFixtures();
        $this->admin();
        DB::table('admin_investor_referral_links')->insert(['id' => 15, 'investor_id' => 1, 'link_type' => 'SM']);

        $this->get('/investors/list')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->component('investors/Index')->where('records.total', 2)->missing('records.data.0.password')->missing('records.data.0.legacy_checksum'));
        $this->get('/investors/accounts/1')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->component('investors/Account')->where('children.0.id', 2)->missing('account.password'));
        $this->get('/investors/master-agents')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('records.data.0.sales_manager_count', 1));
        $this->get('/investors/referral-links')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('records.data.0.url', 'https://partners.gamexsports.com.ph/register/TEST-15'));
        $this->get('/investors/referral-links?link_type=Old')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('records.total', 7)->missing('records.data.0.password'));
    }

    public function test_commission_review_uses_hierarchy_and_manila_day_boundaries_and_excludes_special_or_paid_items(): void
    {
        $this->fixtures();
        $this->admin();
        $this->commission();
        $this->commission(['commission_amount' => '0.20'], true);
        $this->commission(['commission_amount' => '999.00', 'bet_created_at' => '2026-11-01 15:59:59']);
        $this->commission(['commission_amount' => '999.00', 'special_settlement_id' => 4]);
        $this->commission(['commission_amount' => '999.00', 'is_settled' => 'Yes']);
        $this->commission(['commission_amount' => '999.00', 'investor_id' => 3]);

        $this->get('/investors/settlements/commissions/1?date_from=2026-11-02&date_to=2026-11-02')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('investors/Settlement')
                ->where('items.total', 2)->missing('items.data.0.legacy_checksum'));
    }

    public function test_commission_settlement_calculates_the_amount_on_the_server_and_cannot_be_replayed(): void
    {
        $this->fixtures();
        $this->admin();
        $base = $this->commission();
        $referral = $this->commission(['commission_amount' => '0.20'], true);
        $foreign = $this->commission(['investor_id' => 3, 'commission_amount' => '999.00']);
        $payload = $this->payment() + ['amount' => '1000', 'investor_id' => 3, 'total_commissions' => '1000'];

        $this->post('/investors/settlements/commissions/1', $payload)->assertRedirect();
        $this->post('/investors/settlements/commissions/1', $payload)->assertSessionHasErrors('settlement');

        $this->assertDatabaseCount('admin_investor_settled_commissions', 1);
        $this->assertDatabaseHas('admin_investor_settled_commissions', ['investor_id' => 1, 'amount' => '0.30']);
        $this->assertDatabaseHas('admin_investor_commissions', ['id' => $base, 'is_settled' => 'Yes', 'settlement_id' => 1]);
        $this->assertDatabaseHas('admin_investor_sales_representative_commissions', ['id' => $referral, 'settlement_id' => 1]);
        $this->assertDatabaseHas('admin_investor_commissions', ['id' => $foreign, 'is_settled' => 'No']);
        $this->assertDatabaseHas('admin_investors', ['id' => 1, 'wallet' => '50.25']);
        $this->assertDatabaseCount('admin_investor_audits', 1);
    }

    public function test_pending_payout_with_upstream_settled_flag_is_approved_once_without_wallet_replay(): void
    {
        $this->fixtures();
        $this->admin();
        DB::table('admin_investor_payouts')->insert(['id' => 1, 'investor_id' => 1, 'amount' => '25.15', 'status' => 'Pending', 'is_settled' => 'Yes']);

        $this->post('/investors/settlements/payouts/1', $this->payment())->assertRedirect();
        $this->post('/investors/settlements/payouts/1', $this->payment())->assertSessionHasErrors('settlement');

        $this->assertDatabaseCount('admin_investor_settled_payouts', 1);
        $this->assertDatabaseHas('admin_investor_settled_payouts', ['investor_id' => 1, 'amount' => '25.15']);
        $this->assertDatabaseHas('admin_investor_payouts', ['id' => 1, 'status' => 'Approved', 'settlemet_id' => 1]);
        $this->assertDatabaseHas('admin_investors', ['id' => 1, 'wallet' => '50.25']);
    }

    public function test_incomplete_import_and_invalid_payment_details_prevent_settlements(): void
    {
        $this->fixtures();
        $this->admin();
        $this->commission();

        $this->post('/investors/settlements/commissions/1', [])->assertSessionHasErrors(['mode_of_payment', 'reference']);
        DB::table('admin_investor_imports')->delete();
        $this->post('/investors/settlements/commissions/1', $this->payment())->assertSessionHasErrors('investor');

        $this->assertDatabaseCount('admin_investor_settled_commissions', 0);
        $this->assertDatabaseCount('admin_investor_audits', 0);
    }

    public function test_special_settlement_has_separate_permissions_and_requires_verification_before_approval(): void
    {
        $this->fixtures();
        $this->admin(['Special Commission Settlement', 'Special Commission Settlement Verify', 'Special Commission Settlement Approve']);
        DB::table('admin_investor_special_settlement')->insert(['id' => 1, 'investor_id' => 1, 'amount' => '1.15', 'status' => 'Requested']);
        $id = $this->commission(['investor_id' => 1, 'special_settlement_id' => 1, 'commission_amount' => '1.15']);

        $this->post('/investors/special/1/approve', $this->payment())->assertSessionHasErrors('settlement');
        $this->post('/investors/special/1/verify')->assertRedirect();
        $this->post('/investors/special/1/approve', $this->payment())->assertRedirect();
        $this->post('/investors/special/1/approve', $this->payment())->assertSessionHasErrors('settlement');

        $this->assertDatabaseHas('admin_investor_special_settlement', ['id' => 1, 'status' => 'Approved', 'reference' => 'TEST-PAYMENT']);
        $this->assertDatabaseHas('admin_investor_commissions', ['id' => $id, 'is_settled' => 'Yes']);
        $this->assertDatabaseCount('admin_investor_special_settlement_logs', 2);
    }

    public function test_investors_permission_does_not_grant_special_approval(): void
    {
        $this->admin();

        $this->post('/investors/special/1/approve', $this->payment())->assertForbidden();

        $this->assertDatabaseCount('admin_investor_special_settlement_logs', 0);
    }

    public function test_sub_account_update_is_scoped_to_its_investor(): void
    {
        $this->fixtures();
        $this->admin();
        DB::table('admin_investor_sub_users')->insert(['id' => 1, 'investor_id' => 3, 'first_name' => 'Other', 'last_name' => 'Account', 'mobile_number' => '9123456792']);
        $payload = ['first_name' => 'Changed', 'last_name' => 'Account', 'mobile_number' => '9123456792', 'status' => 'Active'];

        $this->post('/investors/accounts/1/sub-accounts/1', $payload)->assertNotFound();

        $this->assertDatabaseHas('admin_investor_sub_users', ['id' => 1, 'investor_id' => 3, 'first_name' => 'Other']);
        $this->assertDatabaseCount('admin_investor_audits', 0);
    }

    public function test_account_creation_hashes_password_and_creates_referrals_without_exposing_hash(): void
    {
        $this->fixtures();
        $this->admin();
        $payload = [
            'outlet_id' => 1, 'territory' => 'NEW', 'username' => 'newinvestor', 'first_name' => 'New', 'last_name' => 'Investor',
            'mobile_number' => '9123456793', 'password' => 'secure-test-password',
            'base_commission' => '1.25', 'commission' => '2.50', 'sub_commission' => '0.50', 'wallet' => '0',
            'category' => 'Regular', 'link_type' => 'New', 'status' => 'Active', 'investor_type_id' => 3,
        ];

        $this->post('/investors/accounts', $payload)->assertRedirect();

        $account = DB::table('admin_investors')->where('username', 'newinvestor')->first();
        $this->assertTrue(Hash::check('secure-test-password', $account->password));
        $this->assertSame(1, (int) $account->investor_type_id);
        $this->assertDatabaseHas('admin_investor_referral_links', ['investor_id' => $account->id, 'link_type' => 'SM']);
        $this->assertDatabaseHas('admin_investor_referral_links', ['investor_id' => $account->id, 'link_type' => 'RP']);
        $this->assertStringNotContainsString('secure-test-password', DB::table('admin_investor_audits')->value('details'));
    }

    public function test_exports_escape_formula_cells_and_never_export_credentials(): void
    {
        $this->fixtures();
        $this->admin();
        DB::table('admin_investors')->where('id', 1)->update(['first_name' => '=DANGEROUS()']);

        $response = $this->get('/investors/list/export');

        $response->assertOk()->assertDownload('investors-list.csv');
        $csv = $response->streamedContent();
        $this->assertStringContainsString("'=DANGEROUS()", $csv);
        $this->assertStringNotContainsString('private-hash', $csv);
        $this->assertStringNotContainsString('legacy_checksum', $csv);
    }

    public function test_ambiguous_hierarchy_refuses_settlement_without_moving_commissions(): void
    {
        $this->fixtures();
        $this->admin();
        $id = $this->commission();
        DB::table('admin_investor_sub_agents')->insert(['investor_id' => 3, 'investor_agent_id' => 2]);

        $this->post('/investors/settlements/commissions/1', $this->payment())->assertSessionHasErrors('investor');

        $this->assertDatabaseCount('admin_investor_settled_commissions', 0);
        $this->assertDatabaseHas('admin_investor_commissions', ['id' => $id, 'is_settled' => 'No']);
    }

    public function test_special_request_with_a_mismatched_amount_cannot_be_verified(): void
    {
        $this->fixtures();
        $this->admin(['Special Commission Settlement Verify']);
        DB::table('admin_investor_special_settlement')->insert(['id' => 1, 'investor_id' => 1, 'amount' => '99.00', 'status' => 'Requested']);
        $this->commission(['investor_id' => 1, 'special_settlement_id' => 1]);

        $this->post('/investors/special/1/verify')->assertSessionHasErrors('settlement');

        $this->assertDatabaseHas('admin_investor_special_settlement', ['id' => 1, 'status' => 'Requested']);
        $this->assertDatabaseCount('admin_investor_special_settlement_logs', 0);
    }

    public function test_account_updates_propagate_territory_but_cannot_replace_wallet_or_username(): void
    {
        $this->fixtures();
        $this->admin();
        $data = [
            'outlet_id' => 1, 'territory' => 'UPDATED', 'first_name' => 'Master', 'last_name' => 'Agent',
            'mobile_number' => '9123456789', 'base_commission' => '1.25', 'commission' => '2.50', 'sub_commission' => '0.50',
            'category' => 'Regular', 'link_type' => 'New', 'status' => 'Active', 'wallet' => '999.99', 'username' => 'injected',
        ];

        $this->put('/investors/accounts/1', $data)->assertRedirect();

        $this->assertDatabaseHas('admin_investors', ['id' => 1, 'territory' => 'UPDATED', 'wallet' => '50.25', 'username' => null]);
        $this->assertDatabaseHas('admin_investors', ['id' => 2, 'territory' => 'UPDATED']);
        $this->assertDatabaseHas('admin_investor_referral_links', ['investor_id' => 2, 'link_type' => 'SS']);
        $this->assertDatabaseHas('admin_investor_referral_links', ['investor_id' => 2, 'link_type' => 'SR']);
    }

    public function test_payout_summary_only_lists_pending_investors_with_their_covered_dates(): void
    {
        $this->fixtures();
        $this->admin();
        DB::table('admin_investor_payouts')->insert([
            ['id' => 1, 'investor_id' => 1, 'amount' => '10.25', 'status' => 'Pending', 'settlemet_id' => null, 'created_at' => '2026-10-31 16:00:00'],
            ['id' => 2, 'investor_id' => 1, 'amount' => '5.50', 'status' => 'Pending', 'settlemet_id' => null, 'created_at' => '2026-11-01 16:00:00'],
            ['id' => 3, 'investor_id' => 3, 'amount' => '99.00', 'status' => 'Approved', 'settlemet_id' => 1, 'created_at' => '2026-11-01 16:00:00'],
        ]);

        $this->get('/investors/payouts')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('records.total', 1)->where('records.per_page', 5)->where('records.data.0.id', 1)
            ->where('records.data.0.amount', fn ($value) => (float) $value === 15.75)
            ->where('records.data.0.min_date', '2026-10-31 16:00:00')->where('records.data.0.max_date', '2026-11-01 16:00:00'));
        $this->get('/investors/payouts?date_from=2026-11-02&date_to=2026-11-02')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('records.data.0.amount', fn ($value) => (float) $value === 5.50));
    }

    public function test_master_account_totals_follow_the_production_hierarchy_categories(): void
    {
        $this->fixtures();
        $this->admin();
        DB::table('admin_investors')->insert(['id' => 4, 'investor_type_id' => 3, 'first_name' => 'Sales', 'last_name' => 'Supervisor']);
        DB::table('admin_investor_sub_agents')->insert(['investor_id' => 2, 'investor_agent_id' => 4]);
        DB::table('admin_investor_sub_agent_players')->insert([
            ['id' => 1, 'investor_agent_id' => 1, 'investor_sub_type_id' => 1],
            ['id' => 2, 'investor_agent_id' => 1, 'investor_sub_type_id' => 2],
            ['id' => 3, 'investor_agent_id' => 2, 'investor_sub_type_id' => 1],
            ['id' => 4, 'investor_agent_id' => 4, 'investor_sub_type_id' => 2],
        ]);

        $this->get('/investors/master-agents')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('records.data.0.total_accounts', 5)->where('records.data.0.sales_manager_count', 1)
            ->where('records.data.0.regular_player_count', 1));

        $this->assertDatabaseCount('admin_investor_sub_agent_players', 4);
    }

    public function test_commission_summary_defaults_to_the_current_manila_month(): void
    {
        $this->travelTo('2026-11-02 12:00:00');
        $this->fixtures();
        $this->admin();
        $this->commission(['bet_created_at' => '2026-10-01 00:00:00']);

        $this->get('/investors/commissions')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('filters.date_from', '2026-11-01')->where('filters.date_to', '2026-11-30')
            ->where('records.per_page', 5)->where('records.data.0.amount', '0.00')
            ->where('records.data.0.bet_count', 0)->where('records.data.0.bet_amount', '0'));
    }

    public function test_commission_bet_metrics_use_player_mappings_and_count_each_bet_once(): void
    {
        $this->fixtures();
        $this->playerFixtures();
        $this->admin();
        DB::table('admin_investor_sub_agent_players')->insert([
            ['id' => 1, 'investor_agent_id' => 2, 'legacy_player_id' => 73089, 'player_id' => 9],
            ['id' => 2, 'investor_agent_id' => 2, 'legacy_player_id' => 73089, 'player_id' => 9],
        ]);
        DB::table('ending_bet_amounts')->insert(['id' => 1, 'game_id' => 1, 'bet_type_id' => 1, 'amount' => '25.50']);
        DB::table('ending_cards')->insert(['id' => 1, 'bet_amount_id' => 1]);
        DB::table('ending_bets')->insert([
            ['id' => 1, 'player_id' => 9, 'legacy_player_id' => 73089, 'card_id' => 1, 'slot_id' => 1, 'created_at' => '2026-11-01 16:00:00'],
            ['id' => 2, 'player_id' => 9, 'legacy_player_id' => 73089, 'card_id' => 1, 'slot_id' => 1, 'created_at' => '2026-11-01 15:59:59'],
            ['id' => 3, 'player_id' => 11, 'legacy_player_id' => null, 'card_id' => 1, 'slot_id' => 1, 'created_at' => '2026-11-01 16:00:00'],
        ]);

        $this->get('/investors/commissions?date_from=2026-11-02&date_to=2026-11-02')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('records.data.0.id', 1)
                ->where('records.data.0.bet_count', 1)->where('records.data.0.bet_amount', '25.5')
                ->where('records.data.1.bet_count', 0));
    }

    public function test_sorting_and_pagination_apply_to_all_matching_records(): void
    {
        $this->fixtures();
        $this->admin();

        $this->get('/investors/list?sort=wallet&direction=desc')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('records.data.0.id', 1));
        $this->get('/investors/list?sort=name&direction=desc')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('records.data.0.id', 3));
        $this->get('/investors/list?sort=not_a_column')->assertSessionHasErrors('sort');
        $this->get('/investors/list?direction=not_a_direction')->assertSessionHasErrors('direction');
    }

    public function test_sub_account_and_hierarchy_dialog_data_require_permission_and_only_show_the_selected_account(): void
    {
        $this->fixtures();
        $this->playerFixtures();
        $this->admin([]);
        $this->get('/investors/accounts/1/sub-accounts')->assertForbidden();
        $this->get('/investors/accounts/1/hierarchy/agents')->assertForbidden();
        $this->admin();
        DB::table('admin_investor_sub_users')->insert([
            ['id' => 1, 'investor_id' => 1, 'first_name' => 'Direct', 'password' => 'private-hash'],
            ['id' => 2, 'investor_id' => 3, 'first_name' => 'Other', 'password' => 'other-hash'],
        ]);
        DB::table('admin_investor_sub_agent_players')->insert([
            ['id' => 1, 'investor_agent_id' => 1, 'investor_sub_type_id' => 2, 'player_id' => 9],
            ['id' => 2, 'investor_agent_id' => 1, 'investor_sub_type_id' => 1, 'player_id' => 10],
        ]);

        $this->get('/investors/accounts/1/sub-accounts')->assertOk()->assertJsonCount(1, 'records')
            ->assertJsonPath('records.0.id', 1)->assertJsonMissingPath('records.0.password');
        $this->get('/investors/accounts/1/hierarchy/agents')->assertOk()->assertJsonCount(1, 'records')
            ->assertJsonPath('records.0.id', 2)->assertJsonMissingPath('records.0.password');
        $this->get('/investors/accounts/1/hierarchy/regular-players')->assertOk()->assertJsonCount(1, 'records')
            ->assertJsonPath('records.0.player_id', 9);
        $this->get('/investors/accounts/999/sub-accounts')->assertNotFound();
    }

    public function test_old_referrals_match_production_roles_destinations_and_urls_in_source_order(): void
    {
        $this->fixtures();
        $this->admin();
        DB::table('admin_investors')->insert(['id' => 4, 'investor_type_id' => 3, 'first_name' => 'Sales', 'last_name' => 'Supervisor']);

        $this->get('/investors/referral-links?link_type=Old')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('records.per_page', 10)->where('records.total', 9)
            ->where('records.data.0.name', 'Master Agent (MA)')->where('records.data.0.link_for', 'Sales Manager')
            ->where('records.data.0.url', 'https://partners.gamexsports.com.ph/register/lygzW/dNBKW')
            ->where('records.data.1.name', 'Master Agent (MA)')->where('records.data.1.link_for', 'Regular Player')
            ->where('records.data.1.url', 'https://gamexsports.com.ph/lygzW/dNBKW/dNBKW')
            ->where('records.data.2.name', 'Sales Manager (SM)')->where('records.data.2.link_for', 'Sales Supervisor')
            ->where('records.data.2.url', 'https://partners.gamexsports.com.ph/register/lygzW/lygzW')
            ->where('records.data.3.link_for', 'Sales Representative')->where('records.data.3.url', 'https://gamexsports.com.ph/lygzW/dNBKW/lygzW')
            ->where('records.data.4.link_for', 'Regular Player')
            ->where('records.data.7.name', 'Sales Supervisor (SS)')->where('records.data.7.link_for', 'Sales Representative')
            ->where('records.data.8.link_for', 'Regular Player'));
    }

    public function test_new_referrals_use_link_ids_and_describe_the_destination_role(): void
    {
        $this->fixtures();
        $this->admin();
        DB::table('admin_investor_referral_links')->insert([
            ['id' => 15, 'investor_id' => 1, 'link_type' => 'SM'],
            ['id' => 16, 'investor_id' => 1, 'link_type' => 'RP'],
        ]);

        $this->get('/investors/referral-links')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('filters.link_type', 'New')->where('records.per_page', 10)
            ->where('records.data.0.name', 'Master Agent (SM)')->where('records.data.0.link_for', 'Sales Manager')
            ->where('records.data.0.url', 'https://partners.gamexsports.com.ph/register/TEST-15')
            ->where('records.data.1.name', 'Master Agent (RP)')->where('records.data.1.link_for', 'Regular Player')
            ->where('records.data.1.url', 'https://gamexsports.com.ph/?referral_code=TEST-16'));
    }

    public function test_referral_search_matches_the_displayed_name_destination_and_url(): void
    {
        $this->fixtures();
        $this->admin();

        $this->get('/investors/referral-links?link_type=Old&search=Master%20Agent%20(MA)')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('records.total', 2));
        $this->get('/investors/referral-links?link_type=Old&search=sales%20representative')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('records.total', 1)->where('records.data.0.link_for', 'Sales Representative'));
        $this->get('/investors/referral-links?link_type=Old&search=partners.gamexsports.com.ph')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('records.total', 3));
    }

    public function test_referrals_sort_and_paginate_the_expanded_links(): void
    {
        $this->fixtures();
        $this->admin();

        $this->get('/investors/referral-links?link_type=Old&per_page=5&page=2')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('records.total', 7)->where('records.current_page', 2)
                ->has('records.data', 2)->where('records.data.0.id', '3-SM')->where('records.data.1.id', '3-RP'));
        $this->get('/investors/referral-links?link_type=Old&sort=link_for&direction=asc')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('records.data.0.id', '1-RP')->where('records.data.0.link_for', 'Regular Player'));
        $this->get('/investors/referral-links?link_type=Old&sort=url&direction=asc')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('records.data.0.url', 'https://gamexsports.com.ph/lygzW/dNBKW/dNBKW'));
    }
}
