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
                $t->id();
                $columns($t);
                $t->string('legacy_checksum', 64)->nullable();
                $t->json('legacy_dates')->nullable();
            });
        };
        $create('outlets', function (Blueprint $t): void {
            $t->string('outlet_name')->nullable();
            $t->unsignedBigInteger('barangay_id')->nullable();
            $t->string('address')->nullable();
            $t->string('is_active')->nullable()->default('Active');
            $t->dateTime('created_at')->nullable();
        });
        foreach (['investor_types', 'investor_sub_types'] as $name) {
            $create($name, function (Blueprint $t): void {
                $t->string('description', 45)->nullable();
                $t->string('code', 45)->nullable();
                $t->string('status')->nullable()->default('Active');
            });
        }
        $create('investor_log_types', fn (Blueprint $t) => $t->string('description')->nullable());
        $create('investors', function (Blueprint $t): void {
            $t->unsignedBigInteger('outlet_id')->nullable();
            $t->unsignedBigInteger('investor_type_id')->nullable();
            $t->string('territory')->nullable();
            foreach (['username', 'first_name', 'last_name'] as $column) {
                $t->string($column, 45)->nullable();
            }
            $t->string('mobile_number', 10)->nullable()->unique();
            $t->string('password')->nullable();
            foreach (['base_commission', 'commission', 'sub_commission', 'wallet'] as $column) {
                $t->decimal($column, 15, 2)->nullable()->default(0);
            }
            $t->string('link_type')->nullable()->default('Old');
            $t->string('category')->nullable()->default('Regular');
            $t->string('status')->nullable()->default('Active');
            $t->dateTime('created_at')->nullable();
            $t->index(['investor_type_id', 'id']);
        });
        $create('investor_sub_agents', function (Blueprint $t): void {
            $t->unsignedBigInteger('investor_id')->nullable()->index();
            $t->unsignedBigInteger('investor_agent_id')->nullable()->index();
        });
        $create('investor_sub_agent_players', function (Blueprint $t): void {
            $t->unsignedBigInteger('investor_agent_id')->nullable()->index();
            $t->unsignedBigInteger('investor_sub_type_id')->nullable();
            $t->unsignedBigInteger('legacy_player_id')->nullable();
            $t->unsignedBigInteger('player_id')->nullable()->index();
            $t->decimal('commission', 15, 2)->nullable()->default(0);
        });
        $create('investor_sub_users', function (Blueprint $t): void {
            $t->unsignedBigInteger('investor_id')->nullable()->index();
            $t->string('first_name', 45)->nullable();
            $t->string('last_name', 45)->nullable();
            $t->string('mobile_number', 10)->nullable()->unique();
            $t->string('password')->nullable();
            $t->string('status')->nullable()->default('Active');
            $t->dateTime('created_at')->nullable();
        });
        $create('investor_referral_links', function (Blueprint $t): void {
            $t->unsignedBigInteger('investor_id')->nullable()->index();
            $t->string('link_type')->nullable();
        });
        foreach (['investor_settled_payouts', 'investor_settled_commissions', 'investor_special_settlement', 'investor_downline_settled_commission'] as $name) {
            $create($name, function (Blueprint $t) use ($name): void {
                $t->unsignedBigInteger('investor_id')->nullable()->index();
                $t->decimal('amount', 15, 2)->nullable()->default(0);
                $t->string('mode_of_payment')->nullable();
                $t->string('reference')->nullable();
                $t->text('remarks')->nullable();
                if ($name === 'investor_special_settlement') {
                    $t->string('status')->nullable()->default('Requested')->index();
                } elseif ($name === 'investor_downline_settled_commission') {
                    $t->unsignedBigInteger('requestor_id')->nullable();
                    $t->unsignedBigInteger('legacy_requestor_id')->nullable();
                    $t->string('type')->nullable();
                    $t->string('status')->nullable()->default('Pending');
                    $t->string('is_manual_request')->nullable()->default('No');
                    $t->dateTime('requested_at')->nullable();
                    $t->dateTime('settled_at')->nullable();
                } else {
                    $t->unsignedBigInteger('processed_by')->nullable();
                    $t->dateTime('processed_at')->nullable();
                }
            });
        }
        $create('investor_special_settlement_logs', function (Blueprint $t): void {
            $t->unsignedBigInteger('special_settlement_id')->nullable()->index('admin_inv_special_log_settlement');
            $t->string('status')->nullable();
            $t->string('type')->nullable();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->dateTime('process_date')->nullable();
        });
        $create('investor_payouts', function (Blueprint $t): void {
            $t->unsignedBigInteger('settlemet_id')->nullable();
            $t->unsignedBigInteger('investor_id')->nullable();
            $t->unsignedBigInteger('legacy_player_id')->nullable();
            $t->unsignedBigInteger('player_id')->nullable();
            $t->decimal('amount', 15, 2)->default(0);
            $t->string('status')->default('Pending');
            $t->string('is_settled')->nullable()->default('No');
            $t->dateTime('created_at')->nullable();
            $t->index(['investor_id', 'status', 'created_at']);
        });
        $create('investor_payout_logs', function (Blueprint $t): void {
            $t->unsignedBigInteger('payout_id')->default(0)->index();
            $t->unsignedBigInteger('investor_id')->default(0);
            $t->string('status')->default('Pending');
            $t->dateTime('requested_at')->nullable();
            $t->dateTime('approved_at')->nullable();
        });
        foreach (['investor_commissions', 'investor_sales_representative_commissions'] as $name) {
            $create($name, function (Blueprint $t) use ($name): void {
                $t->unsignedBigInteger('settlement_id')->nullable();
                $t->unsignedBigInteger('downline_settlement_id')->nullable();
                if ($name === 'investor_commissions') {
                    $t->unsignedBigInteger('special_settlement_id')->nullable()->index();
                }
                $t->unsignedBigInteger('investor_id')->nullable();
                $t->unsignedBigInteger('legacy_player_id')->nullable();
                $t->unsignedBigInteger('player_id')->nullable();
                $t->unsignedBigInteger('bet_id')->nullable();
                $t->dateTime('bet_created_at')->nullable();
                $t->decimal('commission_percent', 10, 2)->nullable()->default(0);
                $t->decimal('commission_amount', 15, 2)->nullable()->default(0);
                $t->string('is_paid')->nullable()->default('No');
                $t->string('is_settled')->nullable()->default('No');
                $t->index(['investor_id', 'is_settled', 'bet_created_at'], $name === 'investor_commissions' ? 'admin_inv_comm_pending' : 'admin_inv_sr_pending');
            });
        }
        $create('investor_logs', function (Blueprint $t): void {
            $t->unsignedBigInteger('log_type_id')->nullable()->default(1);
            $t->unsignedBigInteger('investor_id')->nullable()->index();
            $t->unsignedBigInteger('reference_id')->nullable();
            $t->text('details')->nullable();
            $t->string('ip_address', 100);
            $t->dateTime('created_at')->nullable();
        });
        $create('investor_wallet_movements', function (Blueprint $t): void {
            $t->unsignedBigInteger('investor_id')->nullable()->index();
            $t->unsignedBigInteger('transfer_to')->nullable();
            $t->unsignedBigInteger('legacy_transfer_to')->nullable();
            $t->string('type')->nullable()->default('Agent');
            $t->decimal('token', 15, 2)->nullable()->default(0);
            $t->text('details')->nullable();
            $t->string('process_type')->nullable()->default('Main');
            $t->unsignedBigInteger('processed_by')->nullable();
            $t->dateTime('created_at')->nullable();
        });
        Schema::create('admin_investor_imports', function (Blueprint $t): void {
            $t->id();
            $t->string('source');
            $t->json('report');
            $t->dateTime('verified_at');
        });
        Schema::create('admin_investor_audits', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('user_id');
            $t->unsignedBigInteger('investor_id')->nullable()->index();
            $t->string('action');
            $t->json('details');
            $t->dateTime('created_at');
        });
        foreach (['Investors', 'Special Commission Settlement', 'Special Commission Settlement Verify', 'Special Commission Settlement Approve'] as $name) {
            DB::table('admin_acl_permissions')->insertOrIgnore([
                'name' => $name, 'guard_name' => 'web', 'module' => 'Investors', 'module_order' => 60,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        throw new RuntimeException('Investor history and settlements require a reviewed backup before removal. Use a forward migration.');
    }
};
