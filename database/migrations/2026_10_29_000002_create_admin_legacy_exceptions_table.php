<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_legacy_exceptions', function (Blueprint $table): void {
            $table->string('id', 80)->primary();
            $table->string('reason', 80);
            $table->string('source_checksum', 64);
            $table->longText('payload');
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_legacy_exceptions');
    }
};
