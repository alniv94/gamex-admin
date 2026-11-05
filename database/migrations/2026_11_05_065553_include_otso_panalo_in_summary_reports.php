<?php

use App\Services\SummaryReports;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $reports = app(SummaryReports::class);
        $reports->installTriggers();
        $reports->rebuild();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        throw new RuntimeException('Otso Panalo summary tracking is forward-only. Use a reviewed forward migration.');
    }
};
