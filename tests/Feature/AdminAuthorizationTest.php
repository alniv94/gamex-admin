<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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
        $role->givePermissionTo('Player List');
        $user->assignRole($role);

        $this->get('/players/list')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('auth.user.permissions', ['Player List', 'Player Settings'])
            ->where('auth.user.roles', ['Player Support']));
        $this->getJson('/api/user')->assertOk()->assertJsonPath('user.permissions', ['Player List', 'Player Settings']);
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
        $role->givePermissionTo('Player List');
        $user->assignRole($role);
        $this->get('/players/list')->assertOk();

        $role->revokePermissionTo('Player List');
        $this->get('/players/list')->assertForbidden();
        $user->givePermissionTo('Player List');
        $this->get('/players/list')->assertOk();
        $user->revokePermissionTo('Player List');
        $this->get('/players/list')->assertForbidden();
        $this->getJson('/api/user')->assertOk()->assertJsonPath('user.permissions', []);
    }

    public function test_inactive_and_non_admin_users_cannot_use_assigned_permissions(): void
    {
        $user = $this->endingAdmin([1]);
        $role = Role::create(['name' => 'Player Reader', 'guard_name' => 'web']);
        $role->givePermissionTo('Player List');
        $user->assignRole($role);
        $user->update(['status' => 'Inactive']);

        $this->assertFalse($user->can('Player List'));
        $this->assertSame([], $user->summary()['permissions']);
        $this->get('/players/list')->assertRedirect('/login');

        $nonAdmin = User::factory()->create(['status' => 'Active']);
        $nonAdmin->assignRole($role);
        $this->assertFalse($nonAdmin->can('Player List'));
        $this->assertSame([], $nonAdmin->summary()['permissions']);
    }

    public function test_role_names_and_legacy_role_ids_do_not_implicitly_grant_access(): void
    {
        $this->playerFixtures();
        $user = $this->endingAdmin([]);
        $user->profile()->update(['legacy_role_id' => 1]);
        $user->assignRole(Role::create(['name' => 'Super Admin', 'guard_name' => 'web']));

        $this->get('/players/list')->assertForbidden();
        $this->assertFalse($user->can('Player List'));
    }

    public function test_migration_preserves_exact_grants_including_unknown_legacy_capabilities(): void
    {
        $user = $this->endingAdmin([]);
        $inactive = $this->endingAdmin([]);
        $inactive->update(['status' => 'Inactive']);
        DB::table('admin_acl_permissions')->delete();
        Schema::create('admin_permissions', function (Blueprint $table): void {
            $table->unsignedInteger('id')->primary();
            $table->string('name');
            $table->integer('module_order')->default(0);
            $table->string('module')->nullable();
        });
        Schema::create('admin_user_permissions', function (Blueprint $table): void {
            $table->foreignId('user_id')->constrained('users');
            $table->unsignedInteger('permission_id');
            $table->foreign('permission_id')->references('id')->on('admin_permissions');
            $table->primary(['user_id', 'permission_id']);
        });
        foreach ([1 => 'Player List', 53 => 'Cashout Verify', 91 => 'Grand Slam Update Result'] as $id => $name) {
            DB::table('admin_permissions')->insert(['id' => $id, 'name' => $name]);
            DB::table('admin_user_permissions')->insert(['user_id' => $user->id, 'permission_id' => $id]);
        }
        DB::table('admin_user_permissions')->insert(['user_id' => $inactive->id, 'permission_id' => 1]);
        $migration = require database_path('migrations/2026_10_30_075817_migrate_legacy_admin_permissions.php');

        $migration->up();
        $migration->up();
        $consolidation = require database_path('migrations/2026_10_30_100724_consolidate_admin_permission_catalog.php');
        $consolidation->up();
        $consolidation->up();

        $this->assertSame(['Cashout Verify', 'Grand Slam Update Result', 'Player List'], $user->fresh()->getAllPermissions()->pluck('name')->sort()->values()->all());
        $this->assertSame(['Player List'], $inactive->fresh()->getAllPermissions()->pluck('name')->all());
        $this->assertFalse($inactive->fresh()->can('Player List'));
        $this->assertDatabaseCount('admin_model_permissions', 4);
        $this->assertFalse(Schema::hasTable('admin_user_permissions'));
        $this->assertFalse(Schema::hasTable('admin_permissions'));
        $this->assertDatabaseCount('admin_model_roles', 0);
        $this->assertFalse($user->fresh()->can('Cash Out Approve'));
    }

    public function test_legacy_archive_changes_do_not_change_live_access(): void
    {
        $this->playerFixtures();
        $user = $this->endingAdmin([]);
        $user->profile()->update(['legacy_payload' => ['permission_rows' => [['access_id' => 1]]]]);

        $this->get('/players/list')->assertForbidden();
    }
}
