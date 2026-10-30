<?php

namespace Tests\Feature;

use App\Jobs\SendPlayerNotice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Tests\Support\EndingFixtures;
use Tests\Support\PlayerFixtures;
use Tests\TestCase;

class PlayerManagementTest extends TestCase
{
    use EndingFixtures, PlayerFixtures, RefreshDatabase;

    public function test_list_details_and_mutations_require_the_legacy_permissions(): void
    {
        $this->playerFixtures();
        $this->get('/players/list')->assertRedirect('/login');
        $this->get('/player/9')->assertRedirect('/login');
        $this->endingAdmin([17]);
        $this->get('/players/list')->assertForbidden();
        $this->get('/players/export')->assertForbidden();
        $this->get('/player/9')->assertForbidden();
        $this->get('/players/9/ndrp')->assertForbidden();
        $this->get('/players/9/images/selfie')->assertForbidden();
        $this->post('/players/9/verify')->assertForbidden();
        $this->post('/players/9/reject')->assertForbidden();
        $this->put('/players/9/settings/account')->assertForbidden();
        $this->post('/players/9/fund-accounts/1/verify')->assertForbidden();
        $this->assertDatabaseCount('admin_player_audits', 0);
    }

    public function test_list_matches_legacy_defaults_and_excludes_private_account_fields(): void
    {
        $this->playerFixtures();
        $this->endingAdmin([1]);
        $this->travelTo(now()->setDate(2026, 10, 30));

        $this->get('/players/list')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->component('players/List')->has('players.data', 2)->where('players.data.0.id', 10)
            ->where('filters.status', 'Active')->where('filters.account_verified', 'Files Submitted')
            ->missing('players.data.0.password')->missing('players.data.0.session_version'));
    }

    public function test_search_filters_duplicates_and_sort_are_applied_on_the_server(): void
    {
        $this->playerFixtures();
        $this->endingAdmin([1]);

        $this->get('/players/list?search=player9%40example.test&sort=id&direction=asc')->assertInertia(fn (AssertableInertia $page) => $page->has('players.data', 1)->where('players.data.0.id', 9));
        $this->get('/players/list?type=Duplicate+Players&account_verified=All&sort=id&direction=asc&per_page=5')->assertInertia(fn (AssertableInertia $page) => $page->has('players.data', 2)->where('players.data.0.id', 9));
        $this->get('/player/9/duplicates')->assertInertia(fn (AssertableInertia $page) => $page->has('players.data', 1)->where('players.data.0.id', 10));
        $this->get('/players/list?date_from=2026-10-17&date_to=2026-10-17')->assertInertia(fn (AssertableInertia $page) => $page->has('players.data', 0));
        $this->getJson('/players/list?sort=password')->assertUnprocessable()->assertJsonValidationErrors('sort');
    }

    public function test_export_contains_all_filtered_rows_and_protects_spreadsheet_cells(): void
    {
        $this->playerFixtures();
        $this->endingAdmin([1]);
        DB::table('players')->where('id', 9)->update(['name' => '=HYPERLINK("unsafe")']);

        $response = $this->get('/players/export');

        $response->assertOk()->assertDownload();
        $csv = $response->streamedContent();
        $this->assertStringContainsString("'=HYPERLINK", $csv);
        $this->assertStringContainsString('player9@example.test', $csv);
        $this->assertStringNotContainsString('player11@example.test', $csv);
        $this->assertStringNotContainsString('private-password', $csv);
    }

    public function test_details_and_activity_are_scoped_to_the_selected_player(): void
    {
        $this->playerFixtures();
        $admin = $this->endingAdmin([1]);
        DB::table('admin_player_logs')->insert(['player_id' => 10, 'action' => 'login', 'details' => 'Another player', 'created_at' => now()]);
        DB::table('admin_player_legacy_logs')->insert(['player_id' => 9, 'user_id' => $admin->id, 'action' => 'verified_player', 'details' => 'Historical evaluation', 'created_at' => '2026-10-16 08:00:00']);

        $this->get('/player/9')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->component('players/Details')->where('player.id', 9)->has('activity.data', 1)
            ->where('activity.data.0.details', 'Existing wallet transaction')->has('rejectReasons', 1)
            ->missing('player.password')->missing('player.selfie_path'));
        $this->get('/player/9?tab=player_logs')->assertInertia(fn (AssertableInertia $page) => $page->has('activity.data', 0));
        $this->get('/player/9?tab=admin_logs')->assertInertia(fn (AssertableInertia $page) => $page->has('activity.data', 1)->where('activity.data.0.details', 'Historical evaluation'));
        $this->get('/player/999')->assertNotFound();
    }

    public function test_verification_enables_controls_records_actor_and_queues_one_notice(): void
    {
        $this->playerFixtures();
        $admin = $this->endingAdmin([1, 23]);
        Queue::fake([SendPlayerNotice::class]);

        $this->post('/players/9/verify', ['expected' => 'Files Submitted'])->assertRedirect('/player/9')->assertSessionHasNoErrors();

        $this->assertDatabaseHas('player_profiles', ['player_id' => 9, 'kyc_status' => 'Verified']);
        $this->assertDatabaseHas('players', ['id' => 9, 'betting' => 'Enabled', 'cashin' => 'Enabled', 'cashout' => 'Enabled']);
        $this->assertDatabaseHas('admin_player_verifications', ['player_id' => 9, 'verified_by' => $admin->id]);
        $this->assertDatabaseHas('admin_player_audits', ['player_id' => 9, 'user_id' => $admin->id, 'action' => 'verified_player']);
        $this->assertDatabaseHas('player_wallet', ['player_id' => 9, 'actual_amount' => '123.45']);
        Queue::assertPushed(SendPlayerNotice::class, fn ($job) => $job->mobile === '+639123456709');
        $this->post('/players/9/verify', ['expected' => 'Files Submitted'])->assertSessionHasErrors('player');
        $this->assertDatabaseCount('admin_player_audits', 1);
        Queue::assertPushed(SendPlayerNotice::class, 1);
    }

    public function test_rejection_requires_an_active_reason_and_returns_kyc_to_pending(): void
    {
        $this->playerFixtures();
        $this->endingAdmin([1, 23]);
        Queue::fake([SendPlayerNotice::class]);

        $this->post('/players/9/reject', ['expected' => 'Files Submitted', 'reject_reason_id' => 2])->assertSessionHasErrors('reject_reason_id');
        $this->assertDatabaseCount('admin_player_audits', 0);
        $this->post('/players/9/reject', ['expected' => 'Files Submitted', 'reject_reason_id' => 1])->assertRedirect('/player/9')->assertSessionHasNoErrors();

        $this->assertDatabaseHas('player_profiles', ['player_id' => 9, 'kyc_status' => 'Pending']);
        $this->assertDatabaseHas('admin_player_audits', ['action' => 'rejected_player', 'reason' => 'Unclear ID']);
        Queue::assertPushed(SendPlayerNotice::class, fn ($job) => str_contains($job->message, 'UNCLEAR ID'));
    }

    public function test_evaluation_is_unavailable_until_history_import_is_verified(): void
    {
        $this->playerFixtures();
        $this->endingAdmin([1, 23]);
        DB::table('admin_player_imports')->delete();
        Queue::fake([SendPlayerNotice::class]);

        $this->post('/players/9/verify', ['expected' => 'Files Submitted'])->assertServiceUnavailable();

        $this->assertDatabaseHas('player_profiles', ['player_id' => 9, 'kyc_status' => 'Files Submitted']);
        $this->assertDatabaseCount('admin_player_audits', 0);
        Queue::assertNothingPushed();
    }

    public function test_settings_require_permission_and_verified_kyc_and_detect_stale_values(): void
    {
        $this->playerFixtures();
        $this->endingAdmin([1]);
        $this->put('/players/11/settings/betting', ['expected' => 'Disabled', 'enabled' => true])->assertForbidden();
        $this->endingAdmin([1, 24]);
        $this->put('/players/9/settings/betting', ['expected' => 'Disabled', 'enabled' => true])->assertSessionHasErrors('player');
        $this->put('/players/11/settings/betting', ['expected' => 'Disabled', 'enabled' => true])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('players', ['id' => 11, 'betting' => 'Enabled']);
        $this->put('/players/11/settings/betting', ['expected' => 'Disabled', 'enabled' => false])->assertSessionHasErrors('player');
        $this->assertDatabaseCount('admin_player_audits', 1);
    }

    public function test_deactivation_requires_reason_and_invalidates_player_sessions(): void
    {
        $this->playerFixtures();
        $this->endingAdmin([1, 24]);

        $this->put('/players/9/settings/account', ['enabled' => false, 'expected' => 'Active'])->assertSessionHasErrors('reason');
        $this->assertDatabaseHas('players', ['id' => 9, 'status' => 'Active', 'session_version' => 0]);
        $this->put('/players/9/settings/account', ['enabled' => false, 'expected' => 'Active', 'reason' => 'Requested account review'])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('players', ['id' => 9, 'status' => 'Inactive', 'session_version' => 1]);
        $this->assertDatabaseHas('admin_player_audits', ['action' => 'player_account', 'reason' => 'Requested account review']);
    }

    public function test_fund_review_scopes_ownership_and_selects_only_one_default_account(): void
    {
        $this->playerFixtures();
        $this->endingAdmin([1, 23]);
        Queue::fake([SendPlayerNotice::class]);
        DB::table('admin_player_fund_accounts')->insert([
            ['id' => 1, 'player_id' => 9, 'bank_id' => 1, 'account_number' => '9123456789'],
            ['id' => 2, 'player_id' => 9, 'bank_id' => 1, 'account_number' => '9123456790'],
        ]);

        $this->post('/players/10/fund-accounts/1/verify')->assertNotFound();
        $this->post('/players/9/fund-accounts/1/verify')->assertSessionHasNoErrors();
        $this->post('/players/9/fund-accounts/2/verify')->assertSessionHasNoErrors();

        $this->assertDatabaseHas('admin_player_fund_accounts', ['id' => 1, 'is_verified' => 'Verified', 'is_default' => 'Yes']);
        $this->assertDatabaseHas('admin_player_fund_accounts', ['id' => 2, 'is_verified' => 'Verified', 'is_default' => 'No']);
        $this->post('/players/9/fund-accounts/1/reject', ['reason' => 'Stale evaluation'])->assertSessionHasErrors('player');
        $this->assertDatabaseCount('admin_player_audits', 2);
        Queue::assertPushed(SendPlayerNotice::class, 2);
    }

    public function test_images_are_authenticated_and_confined_to_the_players_private_folder(): void
    {
        $this->playerFixtures();
        $this->endingAdmin([1]);
        Storage::fake('local');
        config(['players.media_root' => Storage::disk('local')->path('')]);
        Storage::disk('local')->put('players/9/selfie.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+a7WQAAAAASUVORK5CYII='));
        DB::table('player_profiles')->where('player_id', 9)->update(['selfie_path' => 'players/9/selfie.png']);

        $this->get('/players/9/images/selfie')->assertOk()->assertHeader('Content-Type', 'image/png')->assertHeader('X-Content-Type-Options', 'nosniff');
        DB::table('player_profiles')->where('player_id', 10)->update(['selfie_path' => 'players/9/selfie.png']);
        $this->get('/players/10/images/selfie')->assertNotFound();
        DB::table('player_profiles')->where('player_id', 9)->update(['selfie_path' => '../.env']);
        $this->get('/players/9/images/selfie')->assertNotFound();
    }

    public function test_ndrp_uses_encrypted_search_and_returns_only_display_fields(): void
    {
        $this->playerFixtures();
        $this->endingAdmin([1]);
        config(['players.pagcor.base_url' => 'https://ndrp.example.test/api', 'players.pagcor.aes_key' => '1234567890123456', 'players.pagcor.member_id' => 'abcdefghijklmnop']);
        Http::preventStrayRequests();
        Http::fake(['https://ndrp.example.test/api/GetAll' => Http::response(['data' => ['totalNumberOfRecords' => 1, 'getAllResultDtos' => [['firstName' => 'Example', 'source' => 'GEL', 'private' => 'excluded']]]])]);

        $this->getJson('/players/9/ndrp')->assertOk()->assertJsonPath('status', 'ready')->assertJsonPath('data.records.0.firstName', 'Example')->assertJsonMissingPath('data.records.0.private');

        Http::assertSent(fn ($request) => $request['memberId'] === 'abcdefghijklmnop' && str_starts_with(base64_decode($request['searchData']), 'Salted__') && ! str_contains($request->body(), 'Example Test Player'));
    }

    public function test_ndrp_configuration_and_connection_failures_are_explicit(): void
    {
        $this->playerFixtures();
        $this->endingAdmin([1]);
        Http::preventStrayRequests();
        config(['players.pagcor.base_url' => '']);
        $this->getJson('/players/9/ndrp')->assertOk()->assertJsonPath('status', 'unavailable');
        Http::assertNothingSent();
        config(['players.pagcor.base_url' => 'https://ndrp.example.test/api', 'players.pagcor.aes_key' => '1234567890123456', 'players.pagcor.member_id' => 'abcdefghijklmnop']);
        Http::fake(['https://ndrp.example.test/api/GetAll' => Http::failedConnection()]);
        $this->getJson('/players/9/ndrp')->assertOk()->assertJsonPath('status', 'error');
    }
}
