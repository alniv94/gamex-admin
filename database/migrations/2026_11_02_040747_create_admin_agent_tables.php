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
        $create = function (string $name, Closure $columns): void {
            Schema::create('admin_'.$name, function (Blueprint $t) use ($columns): void {
                $columns($t);
                $t->string('legacy_checksum', 64)->nullable();
                $t->json('legacy_dates')->nullable();
            });
        };
        $create('ending_agents', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('outlet_id')->nullable()->index();
            $t->unsignedBigInteger('type_id')->nullable()->default(1);
            $t->string('username')->nullable()->unique();
            $t->string('password')->nullable();
            $t->string('is_active')->default('Active');
            $t->string('type')->default('Regular');
            foreach (['commission', 'wallet', 'commission_amount'] as $column) {
                $t->decimal($column, 10, 2)->default(0);
            }
            $t->dateTime('created_at')->nullable();
        });
        $create('ending_agent_info', function (Blueprint $t): void {
            $t->unsignedBigInteger('agent_id')->primary();
            foreach (['first_name', 'middle_name', 'last_name'] as $column) {
                $t->string($column, 30)->nullable();
            }
            $t->string('mobile_number', 10)->nullable()->index();
            $t->string('email_address', 150)->nullable()->index();
            $t->unsignedBigInteger('citymun_id')->default(0);
            $t->string('sub_type')->nullable()->default('Streamer');
            $t->string('page_name', 100)->nullable();
        });
        $create('ending_agent_players', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('legacy_player_id')->nullable()->unique();
            $t->unsignedBigInteger('player_id')->nullable()->index();
            $t->unsignedBigInteger('agent_id')->index();
            $t->unsignedBigInteger('agent_sub_type_id')->nullable()->default(2);
            $t->decimal('commission', 10, 2)->default(0);
            $t->string('withdrawal_type')->default('Withdraw Payout');
            $t->decimal('commission_amount', 10, 2)->default(0);
            $t->integer('sub_agent_category')->nullable()->default(0);
            $t->dateTime('created_at')->nullable();
            $t->dateTime('detached_at')->nullable();
        });
        $create('ending_agent_cashin', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('agent_id')->index();
            $t->unsignedBigInteger('bank_account_id')->default(0);
            $t->string('reference_number')->nullable();
            $t->decimal('amount', 10, 2)->default(0);
            $t->string('screen_shot')->nullable();
            $t->text('notes')->nullable();
            $t->string('type')->default('Cash In');
            $t->string('status')->default('Pending');
            $t->dateTime('created_at')->nullable();
            $t->dateTime('evaluated_at')->nullable();
            $t->unsignedBigInteger('evaluated_by')->default(0);
            $t->text('remarks')->nullable();
            $t->uuid('request_key')->nullable()->unique();
            $t->index(['status', 'created_at']);
        });
        $create('ending_agent_logs', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('agent_id')->index();
            $t->string('action')->nullable();
            $t->unsignedBigInteger('player_id')->nullable();
            $t->unsignedBigInteger('legacy_player_id')->nullable();
            $t->unsignedBigInteger('reference_id')->nullable();
            $t->text('details')->nullable();
            $t->string('ip_address', 100)->nullable();
            $t->dateTime('created_at')->nullable();
        });
        $create('ending_agent_commission_histories', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('player_id')->nullable();
            $t->unsignedBigInteger('legacy_player_id')->nullable();
            $t->decimal('commission', 10, 2)->default(0);
            $t->text('details')->nullable();
            $t->dateTime('created_at')->nullable();
        });
        $create('ending_agent_commission_payments', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('agent_id')->index();
            $t->date('bet_start_date')->nullable();
            $t->date('bet_end_date')->nullable();
            $t->decimal('amount', 10, 2)->default(0);
            $t->unsignedBigInteger('processed_by')->default(0);
            $t->dateTime('created_at')->nullable();
        });
        foreach (['ending_agent_types', 'ending_agent_sub_types', 'ending_agent_default_commission'] as $table) {
            $create($table, function (Blueprint $t) use ($table): void {
                $t->id();
                if ($table === 'ending_agent_types') {
                    $t->string('type')->nullable();
                } elseif ($table === 'ending_agent_sub_types') {
                    $t->string('description', 155)->nullable();
                    $t->string('status')->default('Active');
                } else {
                    $t->unsignedBigInteger('agent_id');
                    $t->decimal('commission', 12, 2)->default(0);
                }
            });
        }
        Schema::create('admin_agent_imports', function (Blueprint $t): void {
            $t->id();
            $t->string('source');
            $t->json('report');
            $t->dateTime('verified_at');
        });
        Schema::create('admin_agent_audits', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('agent_id')->index();
            $t->unsignedBigInteger('user_id');
            $t->string('action');
            $t->json('before')->nullable();
            $t->json('after');
            $t->string('ip_address', 100)->nullable();
            $t->dateTime('created_at');
        });
        foreach (['Ending Agents', 'Ending Agents Create'] as $name) {
            DB::table('admin_acl_permissions')->updateOrInsert(['name' => $name, 'guard_name' => 'web'], [
                'module' => 'Agents', 'module_order' => 4, 'updated_at' => now(),
            ]);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        foreach (['admin_agent_audits', 'admin_agent_imports', 'admin_ending_agent_default_commission', 'admin_ending_agent_sub_types', 'admin_ending_agent_types', 'admin_ending_agent_commission_payments', 'admin_ending_agent_commission_histories', 'admin_ending_agent_logs', 'admin_ending_agent_cashin', 'admin_ending_agent_players', 'admin_ending_agent_info', 'admin_ending_agents'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
