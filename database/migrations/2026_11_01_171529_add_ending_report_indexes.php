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
        Schema::table('ending_bets', function (Blueprint $table): void {
            $table->index(['game_type_id', 'created_at', 'id'], 'ending_bets_report_date');
        });
        Schema::table('ending_game_winners', function (Blueprint $table): void {
            $table->index(['prize_released', 'created_at', 'bet_id'], 'ending_winners_report_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ending_bets', fn (Blueprint $table) => $table->dropIndex('ending_bets_report_date'));
        Schema::table('ending_game_winners', fn (Blueprint $table) => $table->dropIndex('ending_winners_report_date'));
    }
};
