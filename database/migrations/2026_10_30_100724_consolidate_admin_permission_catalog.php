<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        // One-time conversion of the names introduced by the earlier ACL migration.
        $aliases = [
            1 => ['players.view', 'Player List'],
            2 => ['cashin.view', 'Cash In'],
            3 => ['cashout.view', 'Cash Out'],
            8 => ['ending.winners.verify', 'Ending Game Winner Verification'],
            9 => ['ending.winners.approve', 'Ending Game Winner Approval 1'],
            10 => ['ending.games.update', 'Ending Game Settings'],
            11 => ['ending.results.manage', 'Ending Game Result Creation'],
            12 => ['ending.cards.manage', 'Ending Game Bet/Cards Creation'],
            15 => ['settings.users.view', 'Users'],
            17 => ['ending.games.view', 'Ending Games'],
            18 => ['ending.teams.manage', 'Ending Teams'],
            19 => ['ending.leagues.manage', 'Ending Leagues'],
            20 => ['ending.config.manage', 'Ending Config'],
            23 => ['players.verify', 'Player Verify'],
            24 => ['players.settings.update', 'Player Settings'],
            26 => ['cashin.approve', 'Cash In Approve'],
            27 => ['cashin.disapprove', 'Cash In Dispprove'],
            28 => ['cashin.manual', 'Cash In Manual'],
            29 => ['cashout.approve', 'Cash Out Approve'],
            30 => ['cashout.disapprove', 'Cash Out Dispprove'],
            31 => ['cashout.manual', 'Cash Out Manual'],
            32 => ['ending.games.create', 'Ending Game Create'],
            34 => ['ending.winners.view', 'Ending Game Winners'],
            52 => ['cashin.verify', 'Cashin Verify'],
            53 => ['cashout.verify', 'Cashout Verify'],
        ];
        foreach (['module', 'module_order'] as $column) {
            if (! Schema::hasColumn('admin_acl_permissions', $column)) {
                Schema::table('admin_acl_permissions', function (Blueprint $table) use ($column): void {
                    if ($column === 'module') {
                        $table->string('module')->nullable();
                    } else {
                        $table->integer('module_order')->default(0);
                    }
                });
            }
        }

        try {
            DB::transaction(function () use ($aliases): void {
                $catalog = Schema::hasTable('admin_permissions')
                    ? DB::table('admin_permissions')->orderBy('id')->get()->keyBy('id') : collect();
                $renames = [];
                foreach ($aliases as $id => [$alias, $name]) {
                    $renames[$alias] = ['name' => $name];
                }
                foreach ($catalog as $id => $permission) {
                    $alias = $aliases[$id][0] ?? 'legacy.'.$id;
                    $renames[$alias] = [
                        'name' => $permission->name,
                        'module' => $permission->module,
                        'module_order' => $permission->module_order,
                    ];
                }
                $renames['settings.users.manage'] = ['name' => 'Manage users and roles'];
                $permissions = DB::table('admin_acl_permissions')->where('guard_name', 'web')->lockForUpdate()->get()->keyBy('name');
                $destinations = [];
                foreach ($renames as $alias => $attributes) {
                    $name = $attributes['name'];
                    if (trim($name) === '' || isset($destinations[mb_strtolower($name)])) {
                        throw new RuntimeException('The permission catalog contains empty or duplicate names. No grants were changed.');
                    }
                    $destinations[mb_strtolower($name)] = true;
                    $old = $permissions->get($alias);
                    $existing = DB::table('admin_acl_permissions')->where('guard_name', 'web')->where('name', $name)->first();
                    if ($old && $existing && $old->id !== $existing->id) {
                        throw new RuntimeException("Permission name collision: {$name}. No grants were changed.");
                    }
                    if ($old || $existing) {
                        DB::table('admin_acl_permissions')->where('id', ($old ?? $existing)->id)
                            ->update([...$attributes, 'updated_at' => now()]);
                    } else {
                        DB::table('admin_acl_permissions')->insert([
                            ...$attributes, 'guard_name' => 'web', 'created_at' => now(), 'updated_at' => now(),
                        ]);
                    }
                }
                if (DB::table('admin_acl_permissions')->where('guard_name', 'web')->where('name', 'like', 'legacy.%')->exists()) {
                    throw new RuntimeException('A placeholder permission has no original catalog entry. No grants were changed.');
                }
            });

            // The live ACL and its existing pivots are now the only permission store.
            Schema::dropIfExists('admin_user_permissions');
            Schema::dropIfExists('admin_permissions');
        } finally {
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Permission consolidation is forward-only; restoring old aliases would invalidate current access checks.');
    }
};
