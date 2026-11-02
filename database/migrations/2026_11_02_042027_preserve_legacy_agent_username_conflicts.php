<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('admin_ending_agents', function (Blueprint $table): void {
            $table->dropUnique(['username']);
            $table->index('username');
        });
    }

    public function down(): void
    {
        if (DB::table('admin_ending_agents')->whereNotNull('username')->groupBy('username')->havingRaw('COUNT(*) > 1')->exists()) {
            throw new RuntimeException('Resolve duplicate legacy usernames before restoring the unique index.');
        }
        Schema::table('admin_ending_agents', function (Blueprint $table): void {
            $table->dropIndex(['username']);
            $table->unique('username');
        });
    }
};
