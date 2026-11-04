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
        Schema::table('admin_outlets', function (Blueprint $table): void {
            $table->unsignedInteger('management_version')->default(0);
            $table->dateTime('updated_at')->nullable();
            $table->index(['is_active', 'outlet_name']);
        });
        Schema::create('admin_outlet_audits', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('outlet_id')->index();
            $table->string('action');
            $table->json('details');
            $table->dateTime('created_at');
        });
        Schema::create('admin_outlet_imports', function (Blueprint $table): void {
            $table->id();
            $table->string('source');
            $table->json('report');
            $table->dateTime('verified_at');
        });
        DB::table('admin_acl_permissions')->insertOrIgnore([
            'name' => 'Outlets', 'guard_name' => 'web', 'module' => 'Settings', 'module_order' => 90,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('admin_acl_permissions')->where('name', 'Outlets')->where('guard_name', 'web')
            ->update(['module' => 'Settings', 'module_order' => 90]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        throw new RuntimeException('Outlet records and audit history must be retained. Use a forward migration.');
    }
};
