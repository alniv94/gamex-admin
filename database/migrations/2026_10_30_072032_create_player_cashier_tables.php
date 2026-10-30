<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['player_cashin', 'player_cashout'] as $name) {
            Schema::create($name, function (Blueprint $table) use ($name): void {
                $table->id();
                $table->unsignedBigInteger('player_id')->nullable()->index();
                $table->unsignedBigInteger('legacy_player_id')->nullable();
                $table->unsignedBigInteger('legacy_player_mapping_id')->nullable();
                $table->unsignedBigInteger('bank_id')->default(0);
                $table->string('account_number')->nullable();
                if ($name === 'player_cashin') {
                    $table->unsignedBigInteger('bank_account_id')->default(0);
                    $table->string('reference_number')->nullable();
                    $table->string('screen_shot')->nullable();
                    $table->text('notes')->nullable();
                    $table->text('link')->nullable();
                    $table->unsignedTinyInteger('approve_level')->default(0);
                } else {
                    $table->string('account_name')->nullable();
                }
                $table->decimal('amount', 10, 2);
                $table->string('type', 32)->default($name === 'player_cashin' ? 'Cash In' : 'Cash Out');
                $table->string('status', 16)->default('Pending');
                $table->dateTime('created_at')->nullable()->index();
                $table->dateTime('evaluated_at')->nullable();
                $table->unsignedBigInteger('evaluated_by')->nullable();
                $table->text('remarks')->nullable();
                $table->uuid('request_key')->nullable()->unique();
                $table->char('legacy_checksum', 64)->nullable();
                $table->json('legacy_dates')->nullable();
                $table->index(['status', 'created_at', 'id']);
                $table->index(['status', 'evaluated_at', 'id']);
            });
        }
        Schema::create('admin_player_cashier_imports', function (Blueprint $table): void {
            $table->id();
            $table->string('source');
            $table->json('report');
            $table->dateTime('verified_at');
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Cashier requests retain financial references and audit history. Use a reviewed forward migration.');
    }
};
