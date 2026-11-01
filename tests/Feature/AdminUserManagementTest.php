<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Auth\AdminPermissions;
use App\Services\Auth\AdminSms;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Support\EndingFixtures;
use Tests\TestCase;

class AdminUserManagementTest extends TestCase
{
    use EndingFixtures, RefreshDatabase;

    private function manager(): User
    {
        $user = $this->endingAdmin([]);
        $user->givePermissionTo(AdminPermissions::MANAGE_USERS);

        return $user;
    }

    private function target(): User
    {
        $user = User::factory()->create(['name' => 'Target Admin', 'status' => 'Active']);
        $user->profile()->create([]);

        return $user;
    }

    private function payload(?User $user = null): array
    {
        return [
            'name' => $user?->name ?? 'New Admin', 'mobile_number' => $user?->mobile_number ?? '0917 123 4567',
            'email' => $user?->email ?? 'new-admin@example.test', 'roles' => [], 'permissions' => [],
            'version' => $user?->profile->management_version ?? 0,
        ];
    }

    public function test_only_authorized_administrators_can_view_and_manage_users(): void
    {
        $this->get('/settings/users')->assertRedirect('/login');
        $this->get('/settings/users/create')->assertRedirect('/login');
        $reader = $this->endingAdmin([]);
        $this->get('/settings/users')->assertForbidden();
        $reader->givePermissionTo(AdminPermissions::VIEW_USERS);
        $this->get('/settings/users/create')->assertForbidden();
        $this->get('/settings/users')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->component('settings/Users')->has('users.data', 1)
            ->missing('users.data.0.password')->missing('users.data.0.profile'));
        $this->post('/settings/users', $this->payload())->assertForbidden();
        $this->put('/settings/users/'.$reader->id, $this->payload($reader))->assertForbidden();
        $this->put('/settings/users/'.$reader->id.'/status', ['status' => 'Inactive', 'version' => 0])->assertForbidden();
        $this->post('/settings/roles', ['name' => 'Manager', 'permissions' => []])->assertForbidden();
        $role = Role::create(['name' => 'Existing', 'guard_name' => 'web']);
        $this->put('/settings/roles/'.$role->id, ['name' => 'Changed', 'permissions' => [], 'version' => 0])->assertForbidden();
        $this->assertDatabaseCount('admin_user_management_logs', 0);
    }

    public function test_create_page_loads_only_admin_roles_and_permissions_without_other_user_records(): void
    {
        $this->manager();
        $role = Role::create(['name' => 'Player reader', 'guard_name' => 'web']);
        $role->givePermissionTo('Player List');
        Role::create(['name' => 'API reader', 'guard_name' => 'api']);
        Permission::create(['name' => 'api.only', 'guard_name' => 'api']);

        $this->get('/settings/users/create')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->component('settings/CreateUser')->missing('users')->has('roles', 1)
            ->where('roles.0.name', 'Player reader')->where('roles.0.permissions', ['Player List'])
            ->where('permissions', fn ($permissions) => collect($permissions)->contains('name', 'Player List')
                && ! collect($permissions)->contains('name', 'api.only')));
    }

    public function test_invalid_create_submission_returns_to_the_create_page_with_errors(): void
    {
        $this->manager();

        $this->from('/settings/users/create')->post('/settings/users', [
            ...$this->payload(), 'mobile_number' => 'invalid',
        ])->assertRedirect('/settings/users/create')->assertSessionHasErrors('mobile_number');

        $this->assertDatabaseCount('users', 1);
    }

    public function test_creation_combines_roles_and_direct_grants_without_creating_fake_legacy_data(): void
    {
        $manager = $this->manager();
        $role = Role::create(['name' => 'Player reader', 'guard_name' => 'web']);
        $role->givePermissionTo('Player List');

        $this->from('/settings/users/create')->post('/settings/users', [
            ...$this->payload(), 'roles' => [$role->id], 'permissions' => ['Cash In'],
            'status' => 'Inactive', 'password' => 'injected-password', 'legacy_admin_id' => 76,
        ])->assertRedirect('/settings/users')->assertSessionHasNoErrors();

        $user = User::where('email', 'new-admin@example.test')->firstOrFail();
        $this->assertSame('+639171234567', $user->mobile_number);
        $this->assertSame('Active', $user->status);
        $this->assertNull($user->profile->legacy_admin_id);
        $this->assertNull($user->profile->legacy_payload);
        $this->assertNull($user->profile->source_checksum);
        $this->assertFalse(Hash::check('injected-password', $user->password));
        $this->assertTrue($user->can('Player List'));
        $this->assertTrue($user->can('Cash In'));
        $this->assertFalse($user->can(AdminPermissions::MANAGE_USERS));
        $this->assertDatabaseHas('admin_user_management_logs', ['actor_id' => $manager->id, 'subject_id' => $user->id, 'action' => 'create_user']);
        $audit = DB::table('admin_user_management_logs')->first();
        $this->assertStringNotContainsString('password', $audit->after);
        $this->get('/settings/users?search=New+Admin')->assertInertia(fn (AssertableInertia $page) => $page
            ->has('users.data', 1)->where('users.data.0.inherited_permissions', ['Player List'])
            ->where('users.data.0.direct_permissions', ['Cash In'])
            ->where('users.data.0.effective_permissions', ['Cash In', 'Player List']));

        Auth::guard('web')->logout();
        $sentCode = '';
        $this->mock(AdminSms::class)->shouldReceive('send')->once()->withArgs(function ($mobile, $code) use (&$sentCode): bool {
            $sentCode = $code;

            return $mobile === '+639171234567';
        });
        $this->withCredentials();
        $response = $this->postJson('/api/login/otp', ['mobile_number' => '09171234567'])->assertOk();
        $cookie = $response->getCookie(config('session.cookie'));
        $this->withCookie(config('session.cookie'), $cookie->getValue());
        $this->postJson('/api/login', ['challenge_id' => $response->json('challenge_id'), 'otp' => $sentCode])->assertOk();
        $this->assertAuthenticatedAs($user);
    }

    public function test_removing_a_direct_grant_preserves_inherited_access_until_the_role_is_removed(): void
    {
        $this->manager();
        $target = $this->target();
        $role = Role::create(['name' => 'Reader', 'guard_name' => 'web']);
        $role->givePermissionTo('Player List');
        $target->assignRole($role);
        $target->givePermissionTo(['Player List', 'Cash In']);

        $this->put('/settings/users/'.$target->id, [...$this->payload($target), 'roles' => [$role->id]])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertTrue($target->fresh()->can('Player List'));
        $this->assertFalse($target->fresh()->can('Cash In'));
        $this->assertCount(0, $target->fresh()->getDirectPermissions());

        $this->put('/settings/users/'.$target->id, $this->payload($target->fresh()))->assertSessionHasNoErrors();
        $this->assertFalse($target->fresh()->can('Player List'));
        $this->assertDatabaseCount('admin_user_management_logs', 2);
    }

    public function test_roles_can_be_created_renamed_and_updated_with_immediate_effect_for_members(): void
    {
        $manager = $this->manager();
        $this->post('/settings/roles', ['name' => 'Cashier', 'permissions' => ['Cash In']])->assertSessionHasNoErrors();
        $role = Role::findByName('Cashier', 'web');
        $target = $this->target();
        $target->assignRole($role);
        $target->givePermissionTo('Player List');
        $this->actingAs($target)->withSession(['admin_version' => 0]);
        $this->getJson('/api/user')->assertJsonPath('user.permissions', ['Cash In', 'Player List']);

        $this->actingAs($manager)->withSession(['admin_version' => 0]);
        $this->put('/settings/roles/'.$role->id, ['name' => 'Payout reader', 'permissions' => ['Cash Out'], 'version' => 1])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(1, $target->fresh()->profile->management_version);
        $this->actingAs($target)->withSession(['admin_version' => 0]);
        $this->getJson('/api/user')->assertJsonPath('user.permissions', ['Cash Out', 'Player List'])
            ->assertJsonPath('user.roles', ['Payout reader']);
        $this->assertDatabaseHas('admin_user_management_logs', ['action' => 'update_role', 'subject_id' => $role->id]);
    }

    public function test_deactivation_revokes_sessions_and_pending_codes_and_reactivation_does_not_restore_them(): void
    {
        $manager = $this->manager();
        $target = $this->target();
        $target->givePermissionTo('Player List');
        $challenge = (string) Str::uuid();
        DB::table('admin_otp_challenges')->insert([
            'id' => $challenge, 'user_id' => $target->id, 'code_hash' => Hash::make('123456'),
            'session_fingerprint' => str_repeat('a', 64), 'expires_at' => now()->addMinutes(5), 'created_at' => now(),
        ]);

        $this->put('/settings/users/'.$target->id.'/status', ['status' => 'Inactive', 'version' => 0])->assertSessionHasNoErrors();
        $this->assertFalse($target->fresh()->can('Player List'));
        $this->assertNotNull(DB::table('admin_otp_challenges')->where('id', $challenge)->value('consumed_at'));
        $this->actingAs($target->fresh())->withSession(['admin_version' => 0]);
        $this->getJson('/api/user')->assertUnauthorized();

        $this->actingAs($manager->fresh())->withSession(['admin_version' => 0]);
        $this->put('/settings/users/'.$target->id.'/status', ['status' => 'Active', 'version' => 1])->assertSessionHasNoErrors();
        $this->assertTrue($target->fresh()->can('Player List'));
        $this->actingAs($target->fresh())->withSession(['admin_version' => 0]);
        $this->getJson('/api/user')->assertUnauthorized();
        $this->assertSame(2, $target->fresh()->profile->session_version);
    }

    public function test_mobile_changes_revoke_sessions_without_resetting_an_existing_password(): void
    {
        $this->manager();
        $target = $this->target();
        $hash = $target->password;
        $this->put('/settings/users/'.$target->id, [...$this->payload($target), 'mobile_number' => '09181234567', 'email' => 'changed@example.test'])
            ->assertSessionHasNoErrors();
        $this->assertSame($hash, $target->fresh()->password);
        $this->assertNull($target->fresh()->email_verified_at);
        $this->assertSame(1, $target->fresh()->profile->session_version);
        $this->actingAs($target->fresh())->withSession(['admin_version' => 0]);
        $this->getJson('/api/user')->assertUnauthorized();
    }

    public function test_self_deactivation_and_removal_of_the_last_direct_manager_are_rejected_atomically(): void
    {
        $manager = $this->manager();
        $this->put('/settings/users/'.$manager->id.'/status', ['status' => 'Inactive', 'version' => 0])
            ->assertSessionHasErrors('status');
        $this->put('/settings/users/'.$manager->id, [...$this->payload($manager), 'name' => 'Must roll back'])
            ->assertSessionHasErrors('permissions');
        $this->assertSame($manager->name, $manager->fresh()->name);
        $this->assertTrue($manager->fresh()->can(AdminPermissions::MANAGE_USERS));
        $this->assertSame(0, $manager->fresh()->profile->management_version);
        $this->assertDatabaseCount('admin_user_management_logs', 0);
    }

    public function test_last_manager_protection_includes_inherited_permissions_and_ignores_inactive_managers(): void
    {
        $manager = $this->manager();
        $role = Role::create(['name' => 'Access manager', 'guard_name' => 'web']);
        $role->givePermissionTo(AdminPermissions::MANAGE_USERS);
        $manager->syncPermissions([]);
        $manager->assignRole($role);
        $inactive = $this->target();
        $inactive->givePermissionTo(AdminPermissions::MANAGE_USERS);
        $inactive->update(['status' => 'Inactive']);

        $this->put('/settings/roles/'.$role->id, ['name' => 'Empty role', 'permissions' => [], 'version' => 0])
            ->assertSessionHasErrors('permissions');
        $this->assertTrue($manager->fresh()->can(AdminPermissions::MANAGE_USERS));
        $this->assertSame('Access manager', $role->fresh()->name);

        $inactive->update(['status' => 'Active']);
        $this->put('/settings/roles/'.$role->id, ['name' => 'Retired manager', 'permissions' => [], 'version' => 0])
            ->assertRedirect('/dashboard')->assertSessionHasNoErrors();
        $this->assertFalse($manager->fresh()->can(AdminPermissions::MANAGE_USERS));
        $this->assertTrue($inactive->fresh()->can(AdminPermissions::MANAGE_USERS));
    }

    public function test_invalid_foreign_guard_and_unknown_assignments_are_rejected(): void
    {
        $this->manager();
        $foreignRole = Role::create(['name' => 'API role', 'guard_name' => 'api']);
        Permission::create(['name' => 'api.only', 'guard_name' => 'api']);
        foreach ([
            ['mobile_number' => 'not-a-phone'],
            ['permissions' => ['missing.permission']],
            ['permissions' => ['api.only']],
            ['roles' => [$foreignRole->id]],
            ['roles' => [99999]],
            ['permissions' => ['Player List', 'Player List']],
            ['email' => 'invalid-email'],
        ] as $invalid) {
            $this->postJson('/settings/users', [...$this->payload(), ...$invalid])->assertUnprocessable();
        }
        $this->postJson('/settings/roles', ['name' => 'Bad role', 'permissions' => ['api.only']])->assertUnprocessable();
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('admin_user_management_logs', 0);
    }

    public function test_duplicate_normalized_mobiles_and_emails_cannot_create_another_account(): void
    {
        $this->manager();
        $this->post('/settings/users', $this->payload())->assertSessionHasNoErrors();
        $this->postJson('/settings/users', [...$this->payload(), 'mobile_number' => '+63 917 123 4567'])
            ->assertUnprocessable()->assertJsonValidationErrors(['mobile_number', 'email']);
        $this->assertDatabaseCount('users', 2);
    }

    public function test_stale_user_role_and_status_edits_cannot_overwrite_newer_changes(): void
    {
        $this->manager();
        $target = $this->target();
        $this->put('/settings/users/'.$target->id, [...$this->payload($target), 'name' => 'Latest'])->assertSessionHasNoErrors();
        $this->put('/settings/users/'.$target->id, $this->payload($target))->assertSessionHasErrors('version');
        $this->put('/settings/users/'.$target->id.'/status', ['status' => 'Inactive', 'version' => 0])->assertSessionHasErrors('version');
        $this->assertSame('Latest', $target->fresh()->name);
        $role = Role::create(['name' => 'Original role', 'guard_name' => 'web']);
        $target->assignRole($role);
        $this->put('/settings/roles/'.$role->id, ['name' => 'Updated role', 'permissions' => ['Player List'], 'version' => 0])->assertSessionHasNoErrors();
        $this->put('/settings/roles/'.$role->id, ['name' => 'Stale role', 'permissions' => [], 'version' => 0])->assertSessionHasErrors('version');
        $this->put('/settings/users/'.$target->id, [...$this->payload($target), 'version' => 1])->assertSessionHasErrors('version');
        $this->assertSame('Updated role', $role->fresh()->name);
        $this->assertSame(2, $target->fresh()->profile->management_version);
    }

    public function test_non_admin_accounts_and_other_guards_cannot_be_managed(): void
    {
        $this->manager();
        $nonAdmin = User::factory()->create();
        $role = Role::create(['name' => 'Foreign', 'guard_name' => 'api']);
        $this->put('/settings/users/'.$nonAdmin->id, [...$this->payload(), 'email' => $nonAdmin->email])->assertNotFound();
        $this->put('/settings/users/'.$nonAdmin->id.'/status', ['status' => 'Inactive', 'version' => 0])->assertNotFound();
        $this->put('/settings/roles/'.$role->id, ['name' => 'Changed', 'permissions' => [], 'version' => 0])->assertNotFound();
        $this->get('/settings/users')->assertInertia(fn (AssertableInertia $page) => $page->has('users.data', 1)->has('roles', 0));
    }

    public function test_search_status_filters_and_pagination_are_applied_together(): void
    {
        $this->manager();
        for ($i = 0; $i < 7; $i++) {
            $user = $this->target();
            $user->update(['status' => $i === 0 ? 'Inactive' : 'Active']);
        }
        $this->get('/settings/users?search=Target&status=Active&per_page=5')->assertInertia(fn (AssertableInertia $page) => $page
            ->has('users.data', 5)->where('users.total', 6)->where('users.per_page', 5));
        $this->get('/settings/users?search=Target&status=Inactive')->assertInertia(fn (AssertableInertia $page) => $page
            ->has('users.data', 1)->where('users.data.0.status', 'Inactive'));
    }

    public function test_legacy_manager_bootstrap_preserves_viewers_and_does_not_trust_a_matching_native_id(): void
    {
        $migration = require database_path('migrations/2026_10_30_083600_add_admin_user_management.php');
        $migration->down();
        Permission::findByName(AdminPermissions::MANAGE_USERS, 'web')->delete();
        Permission::findByName(AdminPermissions::VIEW_USERS, 'web')->update(['name' => 'legacy.15']);
        $legacyManager = $this->target();
        $legacyManager->profile()->update(['legacy_admin_id' => 76]);
        $legacyManager->givePermissionTo('legacy.15');
        $viewer = $this->target();
        $viewer->givePermissionTo('legacy.15');
        $native = User::factory()->create(['id' => 76, 'status' => 'Active']);
        $native->profile()->create([]);
        $native->givePermissionTo('legacy.15');

        $migration->up();
        $consolidation = require database_path('migrations/2026_10_30_100724_consolidate_admin_permission_catalog.php');
        $consolidation->up();

        $this->assertTrue($legacyManager->fresh()->can(AdminPermissions::MANAGE_USERS));
        $this->assertTrue($legacyManager->fresh()->can(AdminPermissions::VIEW_USERS));
        $this->assertTrue($viewer->fresh()->can(AdminPermissions::VIEW_USERS));
        $this->assertFalse($viewer->fresh()->can(AdminPermissions::MANAGE_USERS));
        $this->assertFalse($native->fresh()->can(AdminPermissions::MANAGE_USERS));
        $this->assertFalse(Permission::query()->where('name', 'legacy.15')->exists());
    }
}
