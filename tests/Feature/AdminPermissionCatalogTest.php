<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Support\EndingFixtures;
use Tests\TestCase;

class AdminPermissionCatalogTest extends TestCase
{
    use EndingFixtures, RefreshDatabase;

    private function previousCatalog(): void
    {
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
        DB::table('admin_permissions')->insert([
            ['id' => 1, 'name' => 'Player List', 'module' => 'PlayerList', 'module_order' => 2],
            ['id' => 101, 'name' => 'Create Otso Panalo', 'module' => null, 'module_order' => 0],
        ]);
        Permission::findByName('Player List', 'web')->update(['name' => 'players.view']);
        Permission::create(['name' => 'legacy.101', 'guard_name' => 'web']);
    }

    public function test_consolidation_keeps_permission_ids_and_direct_and_role_grants_and_displays_original_names(): void
    {
        $this->previousCatalog();
        $user = $this->endingAdmin([]);
        $user->givePermissionTo(['players.view', 'Users']);
        $role = Role::create(['name' => 'Otso operator', 'guard_name' => 'web']);
        $role->givePermissionTo('legacy.101');
        $user->assignRole($role);
        $playerId = Permission::findByName('players.view', 'web')->id;
        $otsoId = Permission::findByName('legacy.101', 'web')->id;
        $directGrants = DB::table('admin_model_permissions')->orderBy('permission_id')->get()->toJson();
        $roleGrants = DB::table('admin_role_permissions')->orderBy('permission_id')->get()->toJson();
        $migration = require database_path('migrations/2026_10_30_100724_consolidate_admin_permission_catalog.php');

        $migration->up();

        $this->assertDatabaseHas('admin_acl_permissions', ['id' => $playerId, 'name' => 'Player List', 'module' => 'PlayerList', 'module_order' => 2]);
        $this->assertDatabaseHas('admin_acl_permissions', ['id' => $otsoId, 'name' => 'Create Otso Panalo']);
        $this->assertSame($directGrants, DB::table('admin_model_permissions')->orderBy('permission_id')->get()->toJson());
        $this->assertSame($roleGrants, DB::table('admin_role_permissions')->orderBy('permission_id')->get()->toJson());
        $this->assertTrue($user->fresh()->can('Create Otso Panalo'));
        $this->assertFalse($user->fresh()->can('Manage users and roles'));
        $this->assertFalse(Schema::hasTable('admin_permissions'));
        $this->assertFalse(Schema::hasTable('admin_user_permissions'));
        $this->get('/settings/users')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('permissions', fn ($permissions) => collect($permissions)->contains(fn ($permission) => $permission['name'] === 'Create Otso Panalo'
                && $permission['label'] === 'Create Otso Panalo' && $permission['group'] !== 'Legacy features')));
    }

    public function test_name_collisions_leave_both_permissions_and_their_grants_unchanged(): void
    {
        $this->previousCatalog();
        $user = $this->endingAdmin([]);
        $user->givePermissionTo('players.view');
        $oldId = Permission::findByName('players.view', 'web')->id;
        $new = Permission::create(['name' => 'Player List', 'guard_name' => 'web']);
        $role = Role::create(['name' => 'Existing reader', 'guard_name' => 'web']);
        $role->givePermissionTo($new);
        $migration = require database_path('migrations/2026_10_30_100724_consolidate_admin_permission_catalog.php');

        try {
            $migration->up();
            $this->fail('Expected a permission name collision.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Permission name collision', $exception->getMessage());
        }

        $this->assertDatabaseHas('admin_acl_permissions', ['id' => $oldId, 'name' => 'players.view']);
        $this->assertDatabaseHas('admin_acl_permissions', ['id' => $new->id, 'name' => 'Player List']);
        $this->assertDatabaseHas('admin_model_permissions', ['permission_id' => $oldId, 'model_id' => $user->id]);
        $this->assertDatabaseHas('admin_role_permissions', ['permission_id' => $new->id, 'role_id' => $role->id]);
        $this->assertDatabaseCount('admin_permissions', 2);
    }

    public function test_missing_original_names_abort_conversion_without_removing_the_catalog(): void
    {
        $this->previousCatalog();
        Permission::create(['name' => 'legacy.999', 'guard_name' => 'web']);
        $migration = require database_path('migrations/2026_10_30_100724_consolidate_admin_permission_catalog.php');

        try {
            $migration->up();
            $this->fail('Expected an unresolved placeholder refusal.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('no original catalog entry', $exception->getMessage());
        }

        $this->assertDatabaseHas('admin_acl_permissions', ['name' => 'players.view']);
        $this->assertDatabaseHas('admin_acl_permissions', ['name' => 'legacy.101']);
        $this->assertDatabaseMissing('admin_acl_permissions', ['name' => 'Create Otso Panalo']);
        $this->assertDatabaseCount('admin_permissions', 2);
    }
}
