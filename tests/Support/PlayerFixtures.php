<?php

namespace Tests\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

trait PlayerFixtures
{
    private function playerFixtures(): void
    {
        Schema::create('players', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('mobile_number')->nullable();
            $table->string('email')->nullable();
            $table->string('username')->nullable();
            $table->string('password')->default('private-password');
            $table->string('status')->default('Active');
            $table->unsignedInteger('session_version')->default(0);
            foreach (['betting', 'cashin', 'cashout'] as $column) {
                $table->string($column)->nullable();
            }
            $table->timestamps();
        });
        Schema::create('player_profiles', function (Blueprint $table): void {
            $table->unsignedBigInteger('player_id')->primary();
            foreach (['first_name', 'middle_name', 'last_name', 'gender', 'marital_status', 'birthplace', 'address', 'current_address', 'occupation', 'kyc_status', 'selfie_path', 'valid_id_path'] as $column) {
                $table->string($column)->nullable();
            }
            $table->date('birthdate')->nullable();
            foreach (['nationality_id', 'barangay_id', 'current_barangay_id', 'income_source_id', 'work_type_id', 'business_type_id', 'other_income_type_id', 'gaming_site_id'] as $column) {
                $table->unsignedBigInteger($column)->nullable();
            }
            $table->timestamps();
        });
        foreach (['nationalities', 'income_sources', 'work_types', 'business_types', 'gaming_sites', 'other_income_types', 'barangays', 'cities'] as $name) {
            Schema::create($name, function (Blueprint $table) use ($name): void {
                $table->id();
                $table->string('name');
                if ($name === 'barangays') {
                    $table->unsignedBigInteger('city_id')->nullable();
                }
            });
        }
        Schema::create('player_wallet', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('player_id');
            $table->decimal('actual_amount', 15, 2);
        });
        Schema::create('player_wallet_transaction_types', function (Blueprint $table): void {
            $table->id();
            $table->string('wallet_transaction_type');
        });
        Schema::create('player_wallet_transactions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('player_id');
            $table->unsignedBigInteger('transaction_type_id');
            $table->string('description');
            $table->decimal('amount', 15, 2);
            $table->dateTime('created_at');
        });
        Schema::create('legacy_player_mappings', function (Blueprint $table): void {
            $table->id();
            $table->string('source');
            $table->unsignedBigInteger('legacy_player_id');
            $table->unsignedBigInteger('player_id')->nullable();
            $table->boolean('quarantined')->default(false);
        });
        foreach ([9, 10, 11] as $id) {
            DB::table('players')->insert(['id' => $id, 'name' => 'Example Test Player', 'mobile_number' => '+6391234567'.str_pad((string) $id, 2, '0', STR_PAD_LEFT), 'email' => 'player'.$id.'@example.test', 'created_at' => '2026-10-16 07:58:00']);
            DB::table('player_profiles')->insert(['player_id' => $id, 'first_name' => 'Example', 'last_name' => $id === 11 ? 'Other' : 'Player', 'birthdate' => '1997-08-13', 'kyc_status' => $id === 11 ? 'Verified' : 'Files Submitted']);
        }
        DB::table('legacy_player_mappings')->insert([
            ['source' => config('players.legacy_source'), 'legacy_player_id' => 73089, 'player_id' => 9, 'quarantined' => false],
            ['source' => config('players.legacy_source'), 'legacy_player_id' => 73090, 'player_id' => null, 'quarantined' => true],
        ]);
        DB::table('player_wallet')->insert(['player_id' => 9, 'actual_amount' => '123.45']);
        DB::table('player_wallet_transaction_types')->insert(['id' => 1, 'wallet_transaction_type' => 'Cash In']);
        DB::table('player_wallet_transactions')->insert(['id' => 1, 'player_id' => 9, 'transaction_type_id' => 1, 'description' => 'Existing wallet transaction', 'amount' => '50.25', 'created_at' => '2026-10-16 08:00:00']);
        DB::table('admin_player_reject_reasons')->insert([
            ['id' => 1, 'reason' => 'Unclear ID', 'is_active' => 'Yes'], ['id' => 2, 'reason' => 'Inactive reason', 'is_active' => 'No'],
        ]);
        DB::table('admin_player_banks')->insert(['id' => 1, 'bank' => 'GCash', 'type' => 'E-Wallet', 'is_active' => 'Active']);
        DB::table('admin_player_imports')->insert(['source' => config('players.legacy_source'), 'report' => '{}', 'verified_at' => now()]);
    }
}
