<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        // This mapping is a historical snapshot of the capabilities at cutover.
        $names = [
            1 => 'players.view', 2 => 'cashin.view', 3 => 'cashout.view',
            8 => 'ending.winners.verify', 9 => 'ending.winners.approve',
            10 => 'ending.games.update', 11 => 'ending.results.manage', 12 => 'ending.cards.manage',
            17 => 'ending.games.view', 18 => 'ending.teams.manage', 19 => 'ending.leagues.manage',
            20 => 'ending.config.manage', 23 => 'players.verify', 24 => 'players.settings.update',
            26 => 'cashin.approve', 27 => 'cashin.disapprove', 28 => 'cashin.manual',
            29 => 'cashout.approve', 30 => 'cashout.disapprove', 31 => 'cashout.manual',
            32 => 'ending.games.create', 34 => 'ending.winners.view',
            52 => 'cashin.verify', 53 => 'cashout.verify',
        ];

        DB::transaction(function () use ($names): void {
            foreach (DB::table('admin_permissions')->orderBy('id')->pluck('id') as $id) {
                $names[(int) $id] ??= 'legacy.'.$id;
            }
            foreach ($names as $name) {
                DB::table('admin_acl_permissions')->insertOrIgnore([
                    'name' => $name, 'guard_name' => 'web', 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            $permissionIds = DB::table('admin_acl_permissions')->where('guard_name', 'web')->pluck('id', 'name');
            DB::table('admin_user_permissions')->orderBy('user_id')->orderBy('permission_id')->chunk(500, function ($grants) use ($names, $permissionIds): void {
                $rows = [];
                foreach ($grants as $grant) {
                    $rows[] = [
                        'permission_id' => $permissionIds[$names[(int) $grant->permission_id]],
                        'model_type' => 'App\\Models\\User', 'model_id' => $grant->user_id,
                    ];
                }
                DB::table('admin_model_permissions')->insertOrIgnore($rows);
            });
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Assignments may have changed since cutover; keep them until the schema rollback.
        // The untouched legacy tables remain available to the previous application release.
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
