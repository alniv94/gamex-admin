<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('players')) {
            foreach (['betting', 'cashin', 'cashout'] as $column) {
                if (! Schema::hasColumn('players', $column)) {
                    Schema::table('players', fn (Blueprint $table) => $table->string($column, 16)->nullable());
                }
            }
        }
        if (Schema::hasTable('player_profiles')) {
            foreach ([['first_name', 'last_name', 'birthdate'], ['kyc_status', 'player_id']] as $columns) {
                if (! Schema::hasIndex('player_profiles', $columns)) {
                    Schema::table('player_profiles', fn (Blueprint $table) => $table->index($columns));
                }
            }
        }
        Schema::create('admin_jobs', function (Blueprint $table): void {
            $table->id();
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });
        Schema::create('admin_failed_jobs', function (Blueprint $table): void {
            $table->id();
            $table->string('uuid')->unique();
            $table->text('connection');
            $table->text('queue');
            $table->longText('payload');
            $table->longText('exception');
            $table->timestamp('failed_at')->useCurrent();
        });
        Schema::create('admin_player_legacy_states', function (Blueprint $table): void {
            $table->unsignedBigInteger('legacy_player_id')->primary();
            $table->unsignedBigInteger('player_id')->nullable()->unique();
            $table->string('account_verified', 32);
            $table->string('betting', 16);
            $table->string('cashin', 16);
            $table->string('cashout', 16);
            $table->string('is_active', 16);
            $table->char('legacy_checksum', 64);
        });
        Schema::create('admin_player_verifications', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('legacy_player_id')->nullable()->unique();
            $table->unsignedBigInteger('player_id')->nullable()->unique();
            $table->integer('processing_by')->nullable();
            $table->dateTime('processing_at')->nullable();
            $table->string('player_update', 8)->nullable();
            $table->integer('verified_by')->nullable();
            $table->dateTime('verified_at')->nullable();
            $table->char('legacy_checksum', 64)->nullable();
            $table->json('legacy_dates')->nullable();
        });
        Schema::create('admin_player_reject_reasons', function (Blueprint $table): void {
            $table->id();
            $table->string('reason');
            $table->string('category')->nullable();
            $table->string('is_active', 8)->default('Yes');
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->char('legacy_checksum', 64)->nullable();
            $table->json('legacy_dates')->nullable();
        });
        Schema::create('admin_player_banks', function (Blueprint $table): void {
            $table->id();
            $table->string('bank');
            $table->string('type', 32);
            $table->text('image')->nullable();
            $table->string('for_add_token', 8)->nullable();
            $table->string('for_withdrawal', 8)->nullable();
            $table->string('is_active', 16);
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->char('legacy_checksum', 64)->nullable();
            $table->json('legacy_dates')->nullable();
        });
        Schema::create('admin_player_fund_accounts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('player_id')->nullable()->index();
            $table->unsignedBigInteger('legacy_player_id')->nullable();
            $table->unsignedBigInteger('bank_id');
            $table->string('account_number', 50);
            $table->string('account_name')->nullable();
            $table->string('is_verified', 16)->default('Pending');
            $table->string('is_default', 8)->default('No');
            $table->string('is_active', 16)->default('Active');
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->char('legacy_checksum', 64)->nullable();
            $table->json('legacy_dates')->nullable();
        });
        foreach (['admin_player_logs', 'admin_player_legacy_logs'] as $name) {
            Schema::create($name, function (Blueprint $table) use ($name): void {
                $table->id();
                $table->unsignedBigInteger('player_id')->nullable();
                $table->unsignedBigInteger('legacy_player_id')->nullable();
                if ($name === 'admin_player_legacy_logs') {
                    $table->integer('user_id')->nullable();
                    $table->string('reject_reason')->nullable();
                    $table->text('remarks')->nullable();
                }
                $table->string('action');
                $table->integer('reference_id')->nullable();
                $table->text('details')->nullable();
                $table->string('ip_address', 100)->nullable();
                $table->dateTime('created_at')->nullable();
                $table->char('legacy_checksum', 64)->nullable();
                $table->json('legacy_dates')->nullable();
                $table->index(['player_id', 'created_at', 'id']);
                $table->index(['player_id', 'action', 'id']);
            });
        }
        Schema::create('admin_player_audits', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('player_id');
            $table->foreignId('user_id')->constrained('users');
            $table->string('action');
            $table->text('details');
            $table->json('before');
            $table->json('after');
            $table->string('reason', 500)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->dateTime('created_at');
            $table->index(['player_id', 'created_at', 'id']);
            $table->index(['player_id', 'action', 'id']);
        });
        Schema::create('admin_player_imports', function (Blueprint $table): void {
            $table->id();
            $table->string('source');
            $table->json('report');
            $table->dateTime('verified_at');
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Player management shares live player accounts and retains audit history. Use a reviewed forward migration to reverse this change.');
    }
};
