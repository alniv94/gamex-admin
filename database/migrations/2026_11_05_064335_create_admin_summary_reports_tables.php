<?php

use App\Services\SummaryReports;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('admin_summary_reports', function (Blueprint $table): void {
            $table->unsignedSmallInteger('year')->primary();
            $table->bigInteger('total_players')->default(0);
            $table->bigInteger('bet_count')->default(0);
            $table->decimal('bet_amount', 20, 2)->default(0);
            $table->dateTime('updated_at');
        });
        Schema::create('admin_summary_report_state', function (Blueprint $table): void {
            $table->unsignedTinyInteger('id')->primary();
            $table->unsignedBigInteger('revision')->default(0);
            $table->dateTime('rebuilt_at')->nullable();
            $table->dateTime('next_refresh_at')->nullable();
        });
        DB::table('admin_summary_report_state')->insert(['id' => 1]);
        $reports = app(SummaryReports::class);
        $reports->installTriggers();
        $reports->rebuild();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        app(SummaryReports::class)->removeTriggers();
        Schema::dropIfExists('admin_summary_report_state');
        Schema::dropIfExists('admin_summary_reports');
    }
};
