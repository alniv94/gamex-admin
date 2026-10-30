<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\Models\Role;
use Tests\Support\EndingFixtures;
use Tests\Support\PlayerFixtures;
use Tests\TestCase;

class AdminAuthorizationTest extends TestCase
{
    use EndingFixtures, PlayerFixtures, RefreshDatabase;

    public function test_role_permissions_authorize_requests_and_are_shared_with_the_frontend(): void
    {
        $this->playerFixtures();
        $user = $this->endingAdmin([24]);
        $role = Role::create(['name' => 'Player Support', 'guard_name' => 'web']);
        $role->givePermissionTo('players.view');
        $user->assignRole($role);

        $this->get('/players/list')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('auth.user.permissions', ['players.settings.update', 'players.view'])
            ->where('auth.user.roles', ['Player Support']));
        $this->getJson('/api/user')->assertOk()->assertJsonPath('user.permissions', ['players.settings.update', 'players.view']);
        $this->put('/players/11/settings/betting', ['enabled' => true, 'expected' => 'Disabled'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('players', ['id' => 11, 'betting' => 'Enabled']);
        $this->post('/players/9/verify')->assertForbidden();
    }

    public function test_role_and_direct_permission_revocation_take_effect_on_the_next_request(): void
    {
        $this->playerFixtures();
        $user = $this->endingAdmin([]);
        $role = Role::create(['name' => 'Player Reader', 'guard_name' => 'web']);
        $role->givePermissionTo('players.view');
        $user->assignRole($role);
        $this->get('/players/list')->assertOk();

        $role->revokePermissionTo('players.view');
        $this->get('/players/list')->assertForbidden();
        $user->givePermissionTo('players.view');
        $this->get('/players/list')->assertOk();
        $user->revokePermissionTo('players.view');
        $this->get('/players/list')->assertForbidden();
        $this->getJson('/api/user')->assertOk()->assertJsonPath('user.permissions', []);
    }

    public function test_inactive_and_non_admin_users_cannot_use_assigned_permissions(): void
    {
        $user = $this->endingAdmin([1]);
        $role = Role::create(['name' => 'Player Reader', 'guard_name' => 'web']);
        $role->givePermissionTo('players.view');
        $user->assignRole($role);
        $user->update(['status' => 'Inactive']);

        $this->assertFalse($user->can('players.view'));
        $this->assertSame([], $user->summary()['permissions']);
        $this->get('/players/list')->assertRedirect('/login');

        $nonAdmin = User::factory()->create(['status' => 'Active']);
        $nonAdmin->assignRole($role);
        $this->assertFalse($nonAdmin->can('players.view'));
        $this->assertSame([], $nonAdmin->summary()['permissions']);
    }

    public function test_role_names_and_legacy_role_ids_do_not_implicitly_grant_access(): void
    {
        $this->playerFixtures();
        $user = $this->endingAdmin([]);
        $user->profile()->update(['legacy_role_id' => 1]);
        $user->assignRole(Role::create(['name' => 'Super Admin', 'guard_name' => 'web']));

        $this->get('/players/list')->assertForbidden();
        $this->assertFalse($user->can('players.view'));
    }

    public function test_migration_preserves_exact_grants_including_unknown_legacy_capabilities(): void
    {
        $user = $this->endingAdmin([]);
        $inactive = $this->endingAdmin([]);
        $inactive->update(['status' => 'Inactive']);
        foreach ([1, 53, 91] as $id) {
            DB::table('admin_permissions')->insert(['id' => $id, 'name' => 'Legacy '.$id]);
            DB::table('admin_user_permissions')->insert(['user_id' => $user->id, 'permission_id' => $id]);
        }
        DB::table('admin_user_permissions')->insert(['user_id' => $inactive->id, 'permission_id' => 1]);
        $migration = require database_path('migrations/2026_10_30_075817_migrate_legacy_admin_permissions.php');

        $migration->up();
        $migration->up();

        $this->assertSame(['cashout.verify', 'legacy.91', 'players.view'], $user->fresh()->getAllPermissions()->pluck('name')->sort()->values()->all());
        $this->assertSame(['players.view'], $inactive->fresh()->getAllPermissions()->pluck('name')->all());
        $this->assertFalse($inactive->fresh()->can('players.view'));
        $this->assertDatabaseCount('admin_model_permissions', 4);
        $this->assertDatabaseCount('admin_user_permissions', 4);
        $this->assertDatabaseCount('admin_model_roles', 0);
        $this->assertFalse($user->fresh()->can('cashout.approve'));
    }

    public function test_legacy_archive_changes_do_not_change_live_access(): void
    {
        $this->playerFixtures();
        $user = $this->endingAdmin([]);
        DB::table('admin_permissions')->insert(['id' => 1, 'name' => 'Player List']);
        DB::table('admin_user_permissions')->insert(['user_id' => $user->id, 'permission_id' => 1]);

        $this->get('/players/list')->assertForbidden();
    }
}
