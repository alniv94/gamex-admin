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
        Schema::create('admin_sms_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->uuid('request_id')->unique();
            $table->unsignedBigInteger('user_id')->index();
            $table->string('fingerprint', 64);
            $table->boolean('is_blast');
            $table->unsignedInteger('recipient_count');
            $table->string('status');
            $table->timestamps();
        });
        DB::table('admin_acl_permissions')->insertOrIgnore([
            'name' => 'SMS', 'guard_name' => 'web', 'module' => 'Settings', 'module_order' => 90,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        throw new RuntimeException('SMS submission records prevent duplicate sends and must be retained. Use a forward migration.');
    }
};
