<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_acl_permissions', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('guard_name');
            $table->timestamps();
            $table->unique(['name', 'guard_name']);
        });
        Schema::create('admin_roles', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('guard_name');
            $table->timestamps();
            $table->unique(['name', 'guard_name']);
        });
        Schema::create('admin_model_permissions', function (Blueprint $table): void {
            $table->foreignId('permission_id')->constrained('admin_acl_permissions')->cascadeOnDelete();
            $table->string('model_type');
            $table->unsignedBigInteger('model_id');
            $table->index(['model_id', 'model_type']);
            $table->primary(['permission_id', 'model_id', 'model_type'], 'admin_model_permissions_primary');
        });
        Schema::create('admin_model_roles', function (Blueprint $table): void {
            $table->foreignId('role_id')->constrained('admin_roles')->cascadeOnDelete();
            $table->string('model_type');
            $table->unsignedBigInteger('model_id');
            $table->index(['model_id', 'model_type']);
            $table->primary(['role_id', 'model_id', 'model_type'], 'admin_model_roles_primary');
        });
        Schema::create('admin_role_permissions', function (Blueprint $table): void {
            $table->foreignId('permission_id')->constrained('admin_acl_permissions')->cascadeOnDelete();
            $table->foreignId('role_id')->constrained('admin_roles')->cascadeOnDelete();
            $table->primary(['permission_id', 'role_id']);
        });
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        foreach (['admin_role_permissions', 'admin_model_roles', 'admin_model_permissions', 'admin_roles', 'admin_acl_permissions'] as $table) {
            Schema::dropIfExists($table);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
