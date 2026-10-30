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
        Schema::table('admin_player_banks', function (Blueprint $table): void {
            $table->string('bank')->nullable()->change();
            $table->string('type', 32)->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        throw new RuntimeException('Legacy banks contain null metadata. Restore a non-null constraint only after reviewing existing records.');
    }
};
