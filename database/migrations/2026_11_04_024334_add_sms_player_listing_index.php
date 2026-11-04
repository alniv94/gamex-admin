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
        if (Schema::hasTable('players') && ! Schema::hasIndex('players', 'players_sms_listing_index')) {
            Schema::table('players', function (Blueprint $table): void {
                $table->index(['status', 'name', 'id', 'mobile_number'], 'players_sms_listing_index');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('players') && Schema::hasIndex('players', 'players_sms_listing_index')) {
            Schema::table('players', function (Blueprint $table): void {
                $table->dropIndex('players_sms_listing_index');
            });
        }
    }
};
