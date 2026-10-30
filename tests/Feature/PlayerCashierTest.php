<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Tests\Support\EndingFixtures;
use Tests\Support\PlayerFixtures;
use Tests\TestCase;

class PlayerCashierTest extends TestCase
{
    use EndingFixtures, PlayerFixtures, RefreshDatabase;

    private function cashierFixtures(): void
    {
        $this->playerFixtures();
        Schema::table('player_wallet_transactions', function (Blueprint $table): void {
            $table->unsignedBigInteger('player_wallet_id')->nullable();
            $table->integer('reference_id')->default(0);
        });
        DB::table('player_wallet_transaction_types')->insert(['id' => 2, 'wallet_transaction_type' => 'Withdraw']);
        DB::table('players')->where('id', 9)->update(['cashin' => 'Enabled', 'cashout' => 'Enabled']);
        DB::table('player_profiles')->where('player_id', 9)->update(['kyc_status' => 'Verified']);
        DB::table('admin_player_cashier_imports')->insert(['source' => config('players.legacy_source'), 'report' => '{}', 'verified_at' => now()]);
    }

    private function requestRow(string $kind, array $attributes = []): int
    {
        return DB::table('player_'.$kind)->insertGetId($attributes + [
            'player_id' => 9, 'bank_id' => 1, 'amount' => '25.10', 'status' => 'Pending',
            'created_at' => '2026-10-15 16:00:00',
        ]);
    }

    public static function kinds(): array
    {
        return ['cash in' => ['cashin', 2, 52, 26, 28], 'cash out' => ['cashout', 3, 53, 29, 31]];
    }

    public static function pageKinds(): array
    {
        return ['cash in' => ['cashin', 2], 'cash out' => ['cashout', 3]];
    }

    public static function workflowKinds(): array
    {
        return ['cash in' => ['cashin', 2, 52, 26], 'cash out' => ['cashout', 3, 53, 29]];
    }

    #[DataProvider('pageKinds')]
    public function test_pages_and_exports_require_the_matching_permission(string $kind, int $permission): void
    {
        $this->get('/players/'.$kind)->assertRedirect('/login');
        $this->endingAdmin([1]);

        $this->get('/players/'.$kind)->assertForbidden();
        $this->get('/players/'.$kind.'/export')->assertForbidden();
    }

    #[DataProvider('pageKinds')]
    public function test_list_defaults_to_pending_with_real_wallet_and_preserves_unlinked_requests(string $kind, int $permission): void
    {
        $this->cashierFixtures();
        $this->endingAdmin([$permission]);
        $this->requestRow($kind);
        $this->requestRow($kind, ['player_id' => null, 'created_at' => '2026-10-16 16:00:00']);
        $this->requestRow($kind, ['status' => 'Approved']);

        $this->get('/players/'.$kind)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->component('players/Cashier')->where('kind', $kind)
            ->where('records.total', 2)->where('records.per_page', 5)
            ->where('records.data.0.player_id', null)->where('records.data.0.wallet', null)
            ->where('records.data.1.player_name', 'Example Test Player')
            ->missing('records.data.1.password')->missing('records.data.1.legacy_checksum'));
    }

    public function test_filters_use_manila_day_boundaries_and_evaluation_dates_for_processed_requests(): void
    {
        $this->cashierFixtures();
        $this->endingAdmin([2]);
        $this->requestRow('cashin', ['created_at' => '2026-10-15 15:59:59']);
        $start = $this->requestRow('cashin');
        $end = $this->requestRow('cashin', ['created_at' => '2026-10-16 15:59:59']);
        $this->requestRow('cashin', ['created_at' => '2026-10-16 16:00:00']);

        $this->get('/players/cashin?date_from=2026-10-16&date_to=2026-10-16&sort=amount&direction=asc')
            ->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('records.total', 2)
            ->where('records.data.0.id', $end)->where('records.data.1.id', $start));
        $approved = $this->requestRow('cashin', ['status' => 'Approved', 'created_at' => '2020-01-01 00:00:00', 'evaluated_at' => '2026-10-15 16:00:00']);
        $this->get('/players/cashin?filter=Approved&date_from=2026-10-16&date_to=2026-10-16')
            ->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('records.total', 1)->where('records.data.0.id', $approved));
    }

    public function test_export_includes_all_matching_pages_and_escapes_formula_values(): void
    {
        $this->cashierFixtures();
        $this->endingAdmin([2]);
        for ($i = 0; $i < 7; $i++) {
            $this->requestRow('cashin', ['reference_number' => 'REF-'.$i, 'remarks' => '=1+1']);
        }
        $this->requestRow('cashin', ['status' => 'Approved', 'reference_number' => 'EXCLUDED']);

        $response = $this->get('/players/cashin/export?per_page=5&search=REF-')->assertOk();
        $csv = $response->streamedContent();

        $this->assertStringContainsString('REF-6', $csv);
        $this->assertStringContainsString("'=1+1", $csv);
        $this->assertStringNotContainsString('EXCLUDED', $csv);
    }

    public static function invalidFilters(): array
    {
        return [
            ['sort=password', 'sort'], ['direction=sideways', 'direction'], ['filter=Paid', 'filter'],
            ['date_from=2026-02-30', 'date_from'], ['date_from=2026-10-17&date_to=2026-10-16', 'date_to'],
            ['per_page=999', 'per_page'], ['page=0', 'page'], ['search='.str_repeat('x', 101), 'search'],
        ];
    }

    #[DataProvider('invalidFilters')]
    public function test_invalid_filters_return_422(string $query, string $field): void
    {
        $this->endingAdmin([2]);

        $this->getJson('/players/cashin?'.$query)->assertUnprocessable()->assertJsonValidationErrors($field);
    }

    #[DataProvider('workflowKinds')]
    public function test_verification_does_not_move_tokens_and_approval_posts_exactly_once(string $kind, int $permission, int $verify, int $approve): void
    {
        $this->cashierFixtures();
        $this->endingAdmin([$permission, $verify, $approve]);
        $id = $this->requestRow($kind);

        $this->post('/players/'.$kind.'/'.$id.'/verify', ['expected' => 'Pending'])->assertRedirect();
        $this->assertDatabaseHas('player_'.$kind, ['id' => $id, 'status' => 'Verified']);
        $this->assertDatabaseHas('player_wallet', ['player_id' => 9, 'actual_amount' => '123.45']);
        $this->assertDatabaseCount('player_wallet_transactions', 1);

        $this->post('/players/'.$kind.'/'.$id.'/approve', ['expected' => 'Verified'])->assertRedirect();
        $this->assertDatabaseHas('player_wallet', ['player_id' => 9, 'actual_amount' => $kind === 'cashin' ? '148.55' : '98.35']);
        $this->assertDatabaseHas('player_wallet_transactions', ['reference_id' => $id, 'transaction_type_id' => $kind === 'cashin' ? 1 : 2, 'amount' => '25.10', 'player_wallet_id' => 1]);
        $this->assertDatabaseCount('admin_player_audits', 2);

        $this->postJson('/players/'.$kind.'/'.$id.'/approve', ['expected' => 'Verified'])->assertUnprocessable()->assertJsonValidationErrors('action');
        $this->assertDatabaseCount('player_wallet_transactions', 2);
        $this->assertDatabaseCount('admin_player_audits', 2);
    }

    public function test_insufficient_funds_leave_request_wallet_and_history_unchanged(): void
    {
        $this->cashierFixtures();
        $this->endingAdmin([3, 29]);
        $id = $this->requestRow('cashout', ['amount' => '123.46', 'status' => 'Verified']);

        $this->postJson('/players/cashout/'.$id.'/approve', ['expected' => 'Verified'])->assertUnprocessable()->assertJsonValidationErrors('action');

        $this->assertDatabaseHas('player_cashout', ['id' => $id, 'status' => 'Verified']);
        $this->assertDatabaseHas('player_wallet', ['player_id' => 9, 'actual_amount' => '123.45']);
        $this->assertDatabaseCount('player_wallet_transactions', 1);
        $this->assertDatabaseCount('admin_player_audits', 0);
    }

    public static function disallowedDecisions(): array
    {
        return [
            'pending approval' => ['Pending', 'approve', 26, 'Pending'],
            'stale verification' => ['Verified', 'verify', 52, 'Pending'],
            'unprivileged approval' => ['Verified', 'approve', 52, 'Verified'],
        ];
    }

    #[DataProvider('disallowedDecisions')]
    public function test_invalid_decisions_do_not_change_financial_records(string $status, string $action, int $grant, string $expected): void
    {
        $this->cashierFixtures();
        $this->endingAdmin([2, $grant]);
        $id = $this->requestRow('cashin', ['status' => $status]);
        $response = $this->postJson('/players/cashin/'.$id.'/'.$action, ['expected' => $expected]);
        if ($grant === 26 || $action === 'verify') {
            $response->assertUnprocessable();
        } else {
            $response->assertForbidden();
        }
        $this->assertDatabaseHas('player_cashin', ['id' => $id, 'status' => $status]);
        $this->assertDatabaseCount('player_wallet_transactions', 1);
        $this->assertDatabaseCount('admin_player_audits', 0);
    }

    public function test_rejections_require_remarks_and_do_not_move_tokens(): void
    {
        $this->cashierFixtures();
        $this->endingAdmin([2, 52, 27]);
        $id = $this->requestRow('cashin');

        $this->postJson('/players/cashin/'.$id.'/reject', ['expected' => 'Pending'])->assertUnprocessable()->assertJsonValidationErrors('remarks');
        $this->post('/players/cashin/'.$id.'/reject', ['expected' => 'Pending', 'remarks' => 'Receipt does not match'])->assertRedirect();
        $verified = $this->requestRow('cashin', ['status' => 'Verified']);
        $this->post('/players/cashin/'.$verified.'/disapprove', ['expected' => 'Verified', 'remarks' => 'Duplicate request'])->assertRedirect();

        $this->assertDatabaseHas('player_cashin', ['id' => $id, 'status' => 'Rejected']);
        $this->assertDatabaseHas('player_cashin', ['id' => $verified, 'status' => 'Disapproved']);
        $this->assertDatabaseHas('player_wallet', ['player_id' => 9, 'actual_amount' => '123.45']);
        $this->assertDatabaseCount('player_wallet_transactions', 1);
        $this->assertDatabaseCount('admin_player_audits', 2);
    }

    public function test_unlinked_requests_and_unverified_imports_cannot_be_processed(): void
    {
        $this->cashierFixtures();
        $this->endingAdmin([2, 52]);
        $id = $this->requestRow('cashin', ['player_id' => null]);

        $this->postJson('/players/cashin/'.$id.'/verify', ['expected' => 'Pending'])->assertUnprocessable()->assertJsonValidationErrors('action');
        DB::table('admin_player_cashier_imports')->delete();
        $linked = $this->requestRow('cashin');
        $this->postJson('/players/cashin/'.$linked.'/verify', ['expected' => 'Pending'])->assertUnprocessable()->assertJsonValidationErrors('action');

        $this->assertDatabaseCount('admin_player_audits', 0);
        $this->assertDatabaseCount('player_wallet_transactions', 1);
    }

    #[DataProvider('kinds')]
    public function test_manual_transactions_require_approval_and_are_idempotent(string $kind, int $permission, int $verify, int $approve, int $manual): void
    {
        $this->cashierFixtures();
        $admin = $this->endingAdmin([$permission, $manual]);
        $admin->givePermissionTo($kind.'.approve');
        config(['players.cashier_approval_admin_id' => $admin->id]);
        $payload = ['player_id' => 9, 'amount' => '0.10', 'remarks' => 'Manual adjustment', 'request_key' => (string) Str::uuid(), 'approval_password' => 'wrong'];

        $this->postJson('/players/'.$kind.'/manual', $payload)->assertUnprocessable()->assertJsonValidationErrors('approval_password');
        $this->assertDatabaseCount('player_'.$kind, 0);
        $payload['approval_password'] = 'test-password';
        $this->post('/players/'.$kind.'/manual', $payload)->assertRedirect()->assertSessionHasNoErrors();
        $this->post('/players/'.$kind.'/manual', $payload)->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseCount('player_'.$kind, 1);
        $this->assertDatabaseHas('player_'.$kind, ['type' => 'Manual', 'status' => 'Approved', 'amount' => '0.10']);
        $this->assertDatabaseHas('player_wallet', ['player_id' => 9, 'actual_amount' => $kind === 'cashin' ? '123.55' : '123.35']);
        $this->assertDatabaseCount('player_wallet_transactions', 2);
        $this->assertDatabaseCount('admin_player_audits', 1);
    }

    public function test_manual_validation_does_not_flash_approval_password(): void
    {
        $this->cashierFixtures();
        $this->endingAdmin([2, 28]);

        $this->post('/players/cashin/manual', ['amount' => '-1', 'approval_password' => 'secret-value'])
            ->assertSessionHasErrors(['amount', 'player_id', 'request_key', 'remarks']);

        $this->assertNull(session()->getOldInput('approval_password'));
        $this->assertDatabaseCount('player_cashin', 0);
    }

    #[DataProvider('kinds')]
    public function test_manual_approver_requires_matching_permission_and_is_recorded(string $kind, int $permission, int $verify, int $approve, int $manual): void
    {
        $this->cashierFixtures();
        $approver = $this->endingAdmin([]);
        $operator = $this->endingAdmin([$permission, $manual]);
        config(['players.cashier_approval_admin_id' => $approver->id]);
        $payload = ['player_id' => 9, 'amount' => '1.00', 'remarks' => 'Approved adjustment',
            'request_key' => (string) Str::uuid(), 'approval_password' => 'test-password'];

        $this->postJson('/players/'.$kind.'/manual', $payload)->assertUnprocessable()->assertJsonValidationErrors('approval_password');
        $this->assertDatabaseCount('player_'.$kind, 0);
        $this->assertDatabaseCount('admin_player_audits', 0);
        $this->assertDatabaseHas('player_wallet', ['player_id' => 9, 'actual_amount' => '123.45']);

        $role = Role::create(['name' => 'Cashier Approver', 'guard_name' => 'web']);
        $role->givePermissionTo($kind.'.approve');
        $approver->assignRole($role);
        $this->post('/players/'.$kind.'/manual', $payload)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('player_'.$kind, ['status' => 'Approved', 'evaluated_by' => $operator->id]);
        $audit = DB::table('admin_player_audits')->sole();
        $this->assertSame($operator->id, $audit->user_id);
        $this->assertSame($approver->id, json_decode($audit->after, true, flags: JSON_THROW_ON_ERROR)['approver_id']);

        $role->revokePermissionTo($kind.'.approve');
        $this->postJson('/players/'.$kind.'/manual', [...$payload, 'request_key' => (string) Str::uuid()])
            ->assertUnprocessable()->assertJsonValidationErrors('approval_password');
        $this->assertDatabaseCount('player_'.$kind, 1);
        $this->assertDatabaseCount('admin_player_audits', 1);
    }

    public function test_inactive_approver_cannot_authorize_manual_transactions(): void
    {
        $this->cashierFixtures();
        $approver = $this->endingAdmin([26]);
        $approver->update(['status' => 'Inactive']);
        $this->endingAdmin([2, 28]);
        config(['players.cashier_approval_admin_id' => $approver->id]);

        $this->postJson('/players/cashin/manual', [
            'player_id' => 9, 'amount' => '1.00', 'remarks' => 'Adjustment',
            'request_key' => (string) Str::uuid(), 'approval_password' => 'test-password',
        ])->assertUnprocessable()->assertJsonValidationErrors('approval_password');
        $this->assertDatabaseCount('player_cashin', 0);
        $this->assertDatabaseCount('admin_player_audits', 0);
    }

    public function test_player_lookup_requires_manual_permission_and_only_returns_eligible_players(): void
    {
        $this->cashierFixtures();
        $this->endingAdmin([2, 28]);

        $this->getJson('/players/cashin/players?search=Example')->assertOk()->assertJsonCount(1)
            ->assertJsonPath('0.id', 9)->assertJsonMissingPath('0.password');
        $this->getJson('/players/cashin/players?search=ab')->assertUnprocessable();
        $this->getJson('/players/cashout/players?search=Example')->assertForbidden();
    }

    public function test_manual_cashout_rolls_back_the_request_when_funds_are_insufficient(): void
    {
        $this->cashierFixtures();
        $admin = $this->endingAdmin([3, 31]);
        $admin->givePermissionTo('cashout.approve');
        config(['players.cashier_approval_admin_id' => $admin->id]);

        $this->postJson('/players/cashout/manual', [
            'player_id' => 9, 'amount' => '123.46', 'remarks' => 'Manual withdrawal',
            'request_key' => (string) Str::uuid(), 'approval_password' => 'test-password',
        ])->assertUnprocessable()->assertJsonValidationErrors('action');

        $this->assertDatabaseCount('player_cashout', 0);
        $this->assertDatabaseHas('player_wallet', ['player_id' => 9, 'actual_amount' => '123.45']);
        $this->assertDatabaseCount('player_wallet_transactions', 1);
        $this->assertDatabaseCount('admin_player_audits', 0);
    }

    public function test_manual_transactions_require_separate_permission_and_eligible_player(): void
    {
        $this->cashierFixtures();
        $admin = $this->endingAdmin([2, 28]);
        $admin->givePermissionTo('cashin.approve');
        config(['players.cashier_approval_admin_id' => $admin->id]);
        DB::table('players')->where('id', 9)->update(['cashin' => 'Disabled']);
        $payload = ['player_id' => 9, 'amount' => '10.00', 'remarks' => 'Manual adjustment',
            'request_key' => (string) Str::uuid(), 'approval_password' => 'test-password'];

        $this->postJson('/players/cashin/manual', $payload)->assertUnprocessable()->assertJsonValidationErrors('action');
        $admin->revokePermissionTo('cashin.manual');
        $this->postJson('/players/cashin/manual', $payload)->assertForbidden();

        $this->assertDatabaseCount('player_cashin', 0);
        $this->assertDatabaseCount('player_wallet_transactions', 1);
    }

    public function test_cash_in_refuses_wallet_overflow_without_posting_a_transaction(): void
    {
        $this->cashierFixtures();
        $this->endingAdmin([2, 26]);
        DB::table('player_wallet')->where('player_id', 9)->update(['actual_amount' => '99999999.99']);
        $id = $this->requestRow('cashin', ['status' => 'Verified']);

        $this->postJson('/players/cashin/'.$id.'/approve', ['expected' => 'Verified'])->assertUnprocessable()->assertJsonValidationErrors('action');

        $this->assertDatabaseHas('player_cashin', ['id' => $id, 'status' => 'Verified']);
        $this->assertDatabaseCount('player_wallet_transactions', 1);
        $this->assertDatabaseCount('admin_player_audits', 0);
    }
}
