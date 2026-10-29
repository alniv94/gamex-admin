<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Historical identifiers are retained even when the source has orphaned references.
        // New writes validate relationships in the workflow; imports never invent parents.
        $create = function (string $name, callable $columns): void {
            Schema::create($name, function (Blueprint $table) use ($columns): void {
                $table->id();
                $columns($table);
                $table->char('legacy_checksum', 64)->nullable()->index();
                $table->json('legacy_dates')->nullable();
            });
        };
        foreach (['ending_game_statuses' => 'status', 'ending_game_quarters' => 'quarter', 'ending_game_winner_statuses' => 'status', 'ending_bet_types' => 'type', 'ending_slots' => 'slot'] as $name => $column) {
            $create($name, fn (Blueprint $t) => $t->string($column)->nullable());
        }
        $create('ending_leagues', function (Blueprint $t): void {
            $t->string('league')->nullable();
            $t->text('logo')->nullable();
            $t->string('is_local', 16)->default('Local');
            $t->string('is_active', 16)->default('Active');
            $t->dateTime('created_at')->nullable();
        });
        $create('ending_teams', function (Blueprint $t): void {
            $t->unsignedBigInteger('league_id')->index();
            $t->string('team_name');
            $t->string('team_alias')->nullable();
            $t->text('logo')->nullable();
            $t->string('is_active', 16)->default('Active');
            $t->dateTime('created_at')->nullable();
        });
        $create('ending_games', function (Blueprint $t): void {
            $t->unsignedBigInteger('team_a')->index();
            $t->unsignedBigInteger('team_b')->index();
            $t->dateTime('schedule')->nullable()->index();
            $t->string('betting', 16)->default('Disabled');
            $t->unsignedBigInteger('game_status_id')->nullable()->index();
            foreach (['is_max', 'is_power_ball', 'is_lucky_four', 'prize_released'] as $flag) {
                $t->string($flag, 8)->nullable()->default('No');
            }
            foreach (['enabled_betting_at', 'disabled_betting_at', 'created_at'] as $date) {
                $t->dateTime($date)->nullable();
            }
        });
        $create('ending_config', function (Blueprint $t): void {
            $t->string('config_name')->nullable()->index();
            $t->text('config_desc')->nullable();
            $t->string('config_value');
        });
        foreach (['ending_traditional_bet_amount_config', 'ending_per_quarter_bet_amount_config', 'ending_bet_amount_config'] as $name) {
            $create($name, function (Blueprint $t) use ($name): void {
                $t->decimal('amount', 10, 2)->nullable();
                if ($name === 'ending_bet_amount_config') {
                    $t->decimal('amount_added', 10, 2)->nullable();
                } else {
                    $t->unsignedInteger('card_quantity')->default(0);
                }
                $t->string('status', 16)->nullable()->default('Active');
            });
        }
        $create('ending_bet_amounts', function (Blueprint $t): void {
            $t->unsignedBigInteger('game_id')->index();
            $t->decimal('amount', 10, 2)->nullable();
            $t->unsignedBigInteger('bet_type_id')->index();
            $t->dateTime('created_at')->nullable();
        });
        $create('ending_bet_amount_prizes', function (Blueprint $t): void {
            $t->unsignedBigInteger('bet_amount_id');
            $t->unsignedInteger('quarter_id');
            $t->string('is_cash', 16)->default('Cash');
            $t->decimal('cash_prize', 10, 2)->nullable();
            $t->string('other_prize')->nullable();
            $t->index(['bet_amount_id', 'quarter_id']);
        });
        $create('ending_cards', function (Blueprint $t): void {
            $t->unsignedBigInteger('bet_amount_id')->index();
            $t->string('betting', 16)->default('Disabled');
            $t->string('generated_file', 8)->default('No');
            $t->string('prize_released', 8)->nullable();
            $t->string('refunded', 8)->default('No');
            $t->dateTime('created_at')->nullable();
        });
        $create('ending_bets', function (Blueprint $t): void {
            $t->unsignedBigInteger('player_id')->nullable()->index();
            $t->unsignedBigInteger('legacy_player_id')->nullable()->index();
            $t->unsignedBigInteger('legacy_player_mapping_id')->nullable()->index();
            $t->unsignedBigInteger('card_id');
            $t->unsignedBigInteger('slot_id');
            $t->unsignedInteger('quarter_id')->default(0);
            $t->unsignedInteger('game_type_id')->default(1);
            $t->string('name')->nullable();
            $t->string('geo_lat', 100)->nullable();
            $t->string('geo_long', 100)->nullable();
            $t->string('ip_address', 100)->nullable();
            $t->string('result', 8)->nullable()->default('Lose');
            $t->string('refunded', 8)->default('No');
            $t->dateTime('created_at')->nullable()->index();
            $t->index(['card_id', 'slot_id', 'quarter_id']);
        });
        $create('ending_game_results', function (Blueprint $t): void {
            $t->unsignedBigInteger('game_id');
            $t->unsignedInteger('quarter_id');
            $t->integer('team_a_score');
            $t->integer('team_b_score');
            $t->unsignedBigInteger('slot_id')->index();
            $t->integer('lucky_four_result')->nullable();
            $t->dateTime('created_at')->nullable();
            $t->dateTime('updated_at')->nullable();
            // The legacy source can contain duplicates; preflight reports them.
            $t->index(['game_id', 'quarter_id']);
        });
        $create('ending_game_winners', function (Blueprint $t): void {
            $t->unsignedBigInteger('bet_id');
            $t->unsignedInteger('quarter_id');
            $t->unsignedInteger('winner_status_id')->default(1);
            $t->string('prize_released', 8)->default('No');
            $t->dateTime('created_at')->nullable();
            $t->unsignedBigInteger('verified_by')->nullable();
            $t->unsignedBigInteger('approved_by')->nullable();
            $t->dateTime('approved_at')->nullable();
            $t->index(['bet_id', 'quarter_id']);
        });
        Schema::create('ending_agent_bet_commissions', function (Blueprint $t): void {
            $t->unsignedBigInteger('bet_id')->primary();
            foreach (['agent_percent', 'agent_amount', 'master_agent_percent', 'master_agent_amount', 'total_amount'] as $column) {
                $t->decimal($column, 10, 2)->default(0);
            }
            $t->string('is_paid', 8)->nullable()->default('No');
            $t->integer('processed_by')->nullable();
            $t->dateTime('processed_at')->nullable();
            $t->char('legacy_checksum', 64)->nullable()->index();
            $t->json('legacy_dates')->nullable();
        });
        $create('ending_sub_agent_bet_commissions', function (Blueprint $t): void {
            foreach (['bet_id', 'master_agent_id', 'sub_agent_id', 'processed_by'] as $column) {
                $t->integer($column)->nullable()->index();
            }
            foreach (['sub_agent_percent', 'master_agent_percent', 'master_agent_amount'] as $column) {
                $t->decimal($column, 10, 2)->nullable();
            }
            $t->string('is_paid', 8)->nullable()->default('No');
            $t->dateTime('processed_at')->nullable();
        });
        $create('admin_ending_legacy_logs', function (Blueprint $t): void {
            $t->integer('user_id')->index();
            $t->string('action')->nullable()->index();
            $t->integer('player_id')->default(0);
            $t->integer('reference_id')->index();
            $t->text('details')->nullable();
            $t->string('reject_reason')->nullable();
            $t->text('remarks')->nullable();
            $t->string('ip_address', 100);
            $t->dateTime('created_at')->nullable()->index();
            $t->unsignedBigInteger('game_id')->nullable()->index();
        });
        Schema::create('admin_ending_logs', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('game_id')->index();
            $t->unsignedBigInteger('user_id')->nullable()->index();
            $t->string('action')->index();
            $t->string('subject_type');
            $t->unsignedBigInteger('subject_id');
            $t->json('before')->nullable();
            $t->json('after')->nullable();
            $t->text('reason')->nullable();
            $t->ipAddress('ip_address')->nullable();
            $t->dateTime('created_at')->index();
        });
        Schema::create('admin_ending_imports', function (Blueprint $t): void {
            $t->id();
            $t->string('source');
            $t->json('report');
            $t->dateTime('verified_at');
        });
    }

    public function down(): void
    {
        // No automatic rollback of historical game/bet/audit records in a shared database.
        throw new RuntimeException('Ending history requires a reviewed backup restore; automatic deletion is disabled.');
    }
};
