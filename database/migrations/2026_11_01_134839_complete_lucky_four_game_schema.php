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
        $history = static function (Blueprint $table): void {
            $table->unsignedBigInteger('legacy_id')->nullable()->unique();
            $table->char('legacy_checksum', 64)->nullable();
            $table->json('legacy_payload')->nullable();
            $table->json('legacy_dates')->nullable();
            $table->dateTime('managed_at')->nullable();
        };
        Schema::table('lucky_four_bets', function (Blueprint $table): void {
            $table->foreignId('game_id')->nullable()->constrained('ending_games')->restrictOnDelete();
            $table->char('combination', 4)->nullable();
            $table->string('is_added', 16)->nullable();
            $table->unsignedBigInteger('legacy_id')->nullable()->unique();
            $table->char('legacy_record_checksum', 64)->nullable();
            $table->json('legacy_payload')->nullable();
            $table->dateTime('managed_at')->nullable();
            $table->index(['game_id', 'combination']);
            $table->index(['game_id', 'is_added']);
        });
        foreach (['lucky_four_prizes_settings', 'lucky_four_prizes_setting_logs', 'lucky_four_prizes'] as $name) {
            Schema::create($name, function (Blueprint $table) use ($name, $history): void {
                $table->id();
                if ($name === 'lucky_four_prizes') {
                    $table->foreignId('game_id')->unique()->constrained('ending_games')->restrictOnDelete();
                    $table->decimal('amount_added', 10, 2)->nullable()->default(0);
                }
                if ($name === 'lucky_four_prizes_setting_logs') {
                    $table->foreignId('prizes_setting_id')->constrained('lucky_four_prizes_settings')->restrictOnDelete();
                    $table->dateTime('created_at')->nullable();
                }
                foreach (['jackpot_prize', 'three_digits_prize', 'initial_amount', 'percentage_deduction', 'bet_amount'] as $field) {
                    $table->decimal($field, 10, 2)->nullable()->default(0);
                }
                $history($table);
            });
        }
        Schema::create('lucky_four_winners', function (Blueprint $table) use ($history): void {
            $table->id();
            $table->unsignedBigInteger('ending_bet_id')->unique();
            $table->foreign('ending_bet_id')->references('ending_bet_id')->on('lucky_four_bets')->restrictOnDelete();
            $table->string('winner_status', 16)->default('Verified');
            $table->string('prize_released', 8)->default('No');
            $table->decimal('prize_amount', 10, 2)->nullable()->default(0);
            $table->unsignedBigInteger('verified_by')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->dateTime('approved_at')->nullable();
            $table->dateTime('created_at')->nullable();
            $history($table);
        });
        Schema::create('admin_lucky_four_legacy_logs', function (Blueprint $table) use ($history): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('game_id')->nullable()->index();
            $table->string('action');
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->text('details')->nullable();
            $table->dateTime('created_at')->nullable();
            $history($table);
        });
        Schema::create('admin_lucky_four_audits', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('game_id')->nullable()->index();
            $table->unsignedBigInteger('user_id');
            $table->string('action');
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->string('ip_address', 100)->nullable();
            $table->dateTime('created_at');
        });
        Schema::create('admin_lucky_four_imports', function (Blueprint $table): void {
            $table->id();
            $table->string('source');
            $table->json('report');
            $table->dateTime('verified_at');
        });
        Schema::create('admin_lucky_four_settlements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('winner_id')->unique()->constrained('lucky_four_winners')->restrictOnDelete();
            $table->unsignedBigInteger('player_id');
            $table->unsignedBigInteger('wallet_transaction_id');
            $table->decimal('amount', 10, 2);
            $table->dateTime('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        throw new RuntimeException('Lucky Four retains betting and payment history. Use a reviewed forward migration.');
    }
};
