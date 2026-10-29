<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('users')) {
            if (! Schema::hasColumns('users', ['name', 'mobile_number', 'email', 'password', 'status'])
                || Schema::hasColumn('users', 'mobile_verification_state')) {
                throw new RuntimeException('Apply the player portal account-separation migration before installing admin authentication.');
            }
        } else {
            Schema::create('users', function (Blueprint $table): void {
                $table->id();
                $table->string('name')->nullable();
                $table->string('mobile_number', 13)->nullable()->unique();
                $table->string('email')->nullable()->unique();
                $table->string('username', 25)->nullable()->unique();
                $table->timestamp('email_verified_at')->nullable();
                $table->string('password');
                $table->string('status', 16)->default('Active')->index();
                $table->rememberToken();
                $table->timestamps();
            });
        }
        Schema::create('admin_profiles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->restrictOnDelete();
            $table->unsignedInteger('legacy_admin_id')->unique();
            $table->string('first_name', 30)->nullable();
            $table->string('middle_name', 30)->nullable();
            $table->string('last_name', 30)->nullable();
            $table->string('email_address', 150)->nullable();
            $table->date('birthdate')->nullable();
            $table->integer('legacy_role_id')->nullable();
            $table->longText('legacy_payload');
            $table->string('source_checksum', 64);
            $table->unsignedInteger('session_version')->default(0);
            $table->timestamps();
        });
        Schema::create('admin_permissions', function (Blueprint $table): void {
            $table->unsignedInteger('id')->primary();
            $table->string('name');
            $table->integer('module_order')->default(0);
            $table->string('module')->nullable();
        });
        Schema::create('admin_user_permissions', function (Blueprint $table): void {
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedInteger('permission_id');
            $table->foreign('permission_id')->references('id')->on('admin_permissions')->restrictOnDelete();
            $table->primary(['user_id', 'permission_id']);
        });
        Schema::create('admin_otp_challenges', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('session_fingerprint', 64);
            $table->string('code_hash');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('expires_at')->index();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('consumed_at')->nullable();
            $table->timestamp('created_at');
        });
        Schema::create('admin_auth_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 32);
            $table->ipAddress('ip_address')->nullable();
            $table->timestamp('created_at')->index();
        });
        Schema::create('admin_sessions', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
        Schema::create('admin_cache', function (Blueprint $table): void {
            $table->string('key')->primary();
            $table->mediumText('value');
            $table->integer('expiration');
        });
        Schema::create('admin_cache_locks', function (Blueprint $table): void {
            $table->string('key')->primary();
            $table->string('owner');
            $table->integer('expiration');
        });
    }

    public function down(): void
    {
        // users is shared with the player portal and must survive an admin rollback.
        foreach (['admin_cache_locks', 'admin_cache', 'admin_sessions', 'admin_auth_logs',
            'admin_otp_challenges', 'admin_user_permissions', 'admin_permissions', 'admin_profiles'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
