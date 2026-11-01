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
        Schema::create('lucky_four_bets', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('ending_bet_id')->unique();
            $table->decimal('amount', 10, 2)->nullable();
            $table->char('legacy_checksum', 64)->nullable();
        });
        Schema::create('admin_player_report_imports', function (Blueprint $table): void {
            $table->id();
            $table->string('source');
            $table->unsignedBigInteger('row_count');
            $table->dateTime('verified_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        throw new RuntimeException('Imported betting history must be retained. Use a reviewed forward migration.');
    }
};
