<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $create = function (string $name, callable $columns): void {
            Schema::create($name, function (Blueprint $table) use ($columns): void {
                $table->id();
                $columns($table);
                $table->unsignedBigInteger('legacy_id')->nullable()->unique();
                $table->char('legacy_checksum', 64)->nullable();
                $table->json('legacy_payload')->nullable();
                $table->json('legacy_dates')->nullable();
                $table->dateTime('managed_at')->nullable();
            });
        };
        foreach (['otso_panalo_statuses' => 'name', 'otso_panalo_bet_types' => 'bet_type', 'otso_panalo_winner_types' => 'winner_type'] as $name => $label) {
            $create($name, function (Blueprint $table) use ($label): void {
                $table->string($label)->nullable();
                $table->string('status', 16)->nullable()->default('Active');
            });
        }
        $prizes = function (Blueprint $table): void {
            foreach (['bet_amount', 'jackpot_prize', 'second_prize', 'third_prize', 'fourth_prize', 'pool_percentage'] as $column) {
                $table->decimal($column, 10, 2)->default(0);
            }
        };
        $create('otso_panalo_game_configs', function (Blueprint $table) use ($prizes): void {
            $table->string('name')->unique();
            $table->text('logo')->nullable();
            $prizes($table);
            $table->string('status', 16)->default('Inactive')->index();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
        });
        $create('otso_panalo_games', function (Blueprint $table) use ($prizes): void {
            $table->foreignId('ending_game_id')->unique()->constrained('ending_games')->restrictOnDelete();
            $table->foreignId('game_config_id')->nullable()->constrained('otso_panalo_game_configs')->restrictOnDelete();
            $table->foreignId('game_status_id')->default(1)->constrained('otso_panalo_statuses')->restrictOnDelete();
            $table->dateTime('betting_deadline')->nullable()->index();
            $table->string('betting', 16)->default('Disabled');
            $prizes($table);
            $table->string('prize_released', 8)->default('No');
            $table->string('cancellation_reason', 100)->nullable();
            foreach (['enabled_betting', 'disabled_betting', 'cancelled'] as $event) {
                $table->dateTime($event.'_at')->nullable();
                $table->unsignedBigInteger($event.'_by')->nullable();
            }
            $table->dateTime('winners_generated_at')->nullable();
            $table->dateTime('created_at')->nullable()->index();
            $table->dateTime('updated_at')->nullable();
        });
        $create('otso_panalo_bets', function (Blueprint $table): void {
            $table->foreignId('game_id')->constrained('otso_panalo_games')->restrictOnDelete();
            $table->unsignedBigInteger('player_id')->nullable()->index();
            $table->unsignedBigInteger('legacy_player_id')->nullable()->index();
            $table->unsignedBigInteger('legacy_player_mapping_id')->nullable();
            $table->foreignId('bet_type_id')->constrained('otso_panalo_bet_types')->restrictOnDelete();
            $table->string('name')->nullable();
            foreach (['first', 'second', 'third', 'fourth'] as $quarter) {
                $table->string($quarter.'_quarter_combination', 45)->nullable();
            }
            $table->decimal('amount', 10, 2)->nullable()->default(0);
            $table->string('refunded', 8)->default('No');
            $table->string('added_to_prize', 8)->nullable()->default('No');
            foreach (['geo_lat', 'geo_long', 'ip_address'] as $column) {
                $table->string($column, 100)->nullable();
            }
            $table->dateTime('created_at')->nullable()->index();
            $table->index(['game_id', 'created_at', 'id'], 'otso_bets_game_date');
        });
        $create('otso_panalo_game_results', function (Blueprint $table): void {
            $table->foreignId('game_id')->unique()->constrained('otso_panalo_games')->restrictOnDelete();
            foreach (['first', 'second', 'third', 'fourth'] as $quarter) {
                $table->string($quarter.'_quarter_result', 45)->nullable();
            }
            $table->string('status', 16)->default('For Approval');
            $table->unsignedInteger('version')->default(1);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->dateTime('approved_at')->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
        });
        $create('otso_panalo_bet_quarter_results', function (Blueprint $table): void {
            $table->foreignId('game_id')->constrained('otso_panalo_games')->restrictOnDelete();
            $table->foreignId('bet_id')->constrained('otso_panalo_bets')->restrictOnDelete();
            $table->unsignedTinyInteger('quarter_number');
            $table->string('bet_combination', 45)->nullable();
            $table->string('game_result', 45)->nullable();
            $table->string('quarter_status', 8)->default('Lose');
            $table->dateTime('created_at')->nullable();
            $table->unique(['bet_id', 'quarter_number'], 'otso_bet_quarter_unique');
        });
        $create('otso_panalo_winners', function (Blueprint $table): void {
            $table->foreignId('bet_id')->unique()->constrained('otso_panalo_bets')->restrictOnDelete();
            $table->foreignId('winner_type_id')->constrained('otso_panalo_winner_types')->restrictOnDelete();
            $table->string('winner_status', 16)->default('Verified');
            $table->string('prize_released', 8)->default('No');
            $table->decimal('prize_amount', 15, 2)->default(0);
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->dateTime('approved_at')->nullable();
            $table->dateTime('created_at')->nullable();
        });
        $create('admin_otso_panalo_legacy_logs', function (Blueprint $table): void {
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('game_id')->nullable()->index();
            $table->string('action')->index();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->text('details')->nullable();
            $table->dateTime('created_at')->nullable();
        });
        Schema::create('admin_otso_panalo_audits', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('game_id')->nullable()->index();
            $table->unsignedBigInteger('user_id');
            $table->string('action');
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->string('ip_address', 100)->nullable();
            $table->dateTime('created_at');
        });
        Schema::create('admin_otso_panalo_imports', function (Blueprint $table): void {
            $table->id();
            $table->string('source');
            $table->json('report');
            $table->dateTime('verified_at');
        });
        Schema::create('admin_otso_panalo_settlements', function (Blueprint $table): void {
            $table->id();
            $table->string('kind', 16);
            $table->unsignedBigInteger('reference_id');
            $table->unsignedBigInteger('player_id');
            $table->unsignedBigInteger('wallet_transaction_id');
            $table->decimal('amount', 15, 2);
            $table->dateTime('created_at');
            $table->unique(['kind', 'reference_id'], 'otso_settlement_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        throw new RuntimeException('Otso Panalo retains financial references and history. Use a reviewed forward migration.');
    }
};
