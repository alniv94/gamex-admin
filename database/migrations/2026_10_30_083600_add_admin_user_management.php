<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('admin_profiles', function (Blueprint $table): void {
            $table->unsignedInteger('legacy_admin_id')->nullable()->change();
            $table->longText('legacy_payload')->nullable()->change();
            $table->string('source_checksum', 64)->nullable()->change();
            $table->unsignedInteger('management_version')->default(0);
        });
        Schema::table('admin_roles', function (Blueprint $table): void {
            $table->unsignedInteger('management_version')->default(0);
        });
        Schema::create('admin_user_management_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('action', 40);
            $table->string('subject_type', 16);
            $table->unsignedBigInteger('subject_id');
            $table->json('before')->nullable();
            $table->json('after');
            $table->ipAddress('ip_address')->nullable();
            $table->timestamp('created_at');
            $table->index(['subject_type', 'subject_id']);
        });

        DB::transaction(function (): void {
            DB::table('admin_acl_permissions')->where('guard_name', 'web')->where('name', 'legacy.15')
                ->update(['name' => 'settings.users.view', 'updated_at' => now()]);
            foreach (['settings.users.view', 'settings.users.manage'] as $name) {
                DB::table('admin_acl_permissions')->insertOrIgnore([
                    'name' => $name, 'guard_name' => 'web', 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            // Preserve the legacy Users controller's account-management authority.
            $manager = DB::table('admin_profiles')->where('legacy_admin_id', 76)->value('user_id');
            $view = DB::table('admin_acl_permissions')->where('name', 'settings.users.view')->where('guard_name', 'web')->value('id');
            if ($manager && DB::table('admin_model_permissions')->where('model_type', 'App\\Models\\User')
                ->where('model_id', $manager)->where('permission_id', $view)->exists()) {
                DB::table('admin_model_permissions')->insertOrIgnore([
                    'permission_id' => DB::table('admin_acl_permissions')->where('name', 'settings.users.manage')->where('guard_name', 'web')->value('id'),
                    'model_type' => 'App\\Models\\User', 'model_id' => $manager,
                ]);
            }
        });
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('admin_user_management_logs');
        Schema::table('admin_roles', fn (Blueprint $table) => $table->dropColumn('management_version'));
        Schema::table('admin_profiles', fn (Blueprint $table) => $table->dropColumn('management_version'));
        // Native accounts and live grants must survive a rollback of the management UI.
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
