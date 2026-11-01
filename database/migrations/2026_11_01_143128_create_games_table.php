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
        Schema::create('games', function (Blueprint $table): void {
            $table->id();
            $table->string('game', 155)->nullable();
            $table->text('description')->nullable();
            $table->string('logo', 155)->nullable();
            $table->string('is_active', 16)->nullable()->default('Active')->index();
            $table->string('route', 155)->nullable();
            $table->char('legacy_checksum', 64)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        throw new RuntimeException('Imported game identifiers must be retained. Use a reviewed forward migration.');
    }
};
