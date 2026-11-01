<?php

namespace Tests\Support;

use App\Models\AdminProfile;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;

trait OtsoPanaloFixtures
{
    private function otsoFixtures(): void
    {
        $this->travelTo(now()->setDate(2026, 11, 1)->setTime(0, 0));
        config(['otso.legacy_source' => 'otso-test']);
        Schema::create('players', function (Blueprint $t): void {
            $t->id();
            $t->string('name');
            $t->string('username')->nullable();
        });
        Schema::create('legacy_player_mappings', function (Blueprint $t): void {
            $t->id();
            $t->string('source');
            $t->unsignedBigInteger('legacy_player_id');
            $t->unsignedBigInteger('player_id')->nullable();
            $t->boolean('quarantined')->default(false);
        });
        Schema::create('player_wallet', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('player_id')->unique();
            $t->decimal('actual_amount', 10, 2);
        });
        Schema::create('player_wallet_transaction_types', function (Blueprint $t): void {
            $t->id();
            $t->string('wallet_transaction_type');
            $t->string('reference_table');
        });
        Schema::create('player_wallet_transactions', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('player_wallet_id');
            $t->unsignedBigInteger('player_id');
            $t->unsignedBigInteger('transaction_type_id');
            $t->unsignedBigInteger('reference_id');
            $t->text('description');
            $t->decimal('amount', 10, 2);
            $t->dateTime('created_at');
        });
        DB::table('players')->insert(['id' => 9, 'name' => 'Test Player', 'username' => 'otso_player']);
        DB::table('player_wallet')->insert(['id' => 10, 'player_id' => 9, 'actual_amount' => '100.00']);
        DB::table('legacy_player_mappings')->insert([
            ['id' => 100, 'source' => 'otso-test', 'legacy_player_id' => 73, 'player_id' => 9, 'quarantined' => false],
            ['id' => 101, 'source' => 'otso-test', 'legacy_player_id' => 74, 'player_id' => null, 'quarantined' => true],
        ]);
        DB::table('ending_leagues')->insert(['id' => 1, 'league' => 'NBA']);
        DB::table('ending_teams')->insert([
            ['id' => 1, 'league_id' => 1, 'team_name' => 'Atlanta Hawks', 'team_alias' => 'ATL'],
            ['id' => 2, 'league_id' => 1, 'team_name' => 'Boston Celtics', 'team_alias' => 'BOS'],
        ]);
        foreach ([1 => 'Not yet started', 2 => 'Started', 3 => 'Finished', 4 => 'Cancelled'] as $id => $name) {
            DB::table('otso_panalo_statuses')->insert(compact('id', 'name'));
        }
        foreach ([1 => 'Jackpot', 2 => 'Second Prize', 3 => 'Third Prize', 4 => 'Fourth Prize'] as $id => $winner_type) {
            DB::table('otso_panalo_winner_types')->insert(compact('id', 'winner_type'));
        }
        DB::table('otso_panalo_bet_types')->insert(['id' => 1, 'bet_type' => 'Straight']);
        DB::table('otso_panalo_game_configs')->insert(['id' => 1, 'name' => 'Standard', 'status' => 'Active',
            'bet_amount' => '20.00', 'jackpot_prize' => '1000.00', 'second_prize' => '100.00',
            'third_prize' => '50.00', 'fourth_prize' => '10.00', 'pool_percentage' => '20.00']);
        DB::table('admin_ending_imports')->insert(['source' => 'otso-test', 'report' => '{}', 'verified_at' => now()]);
        DB::table('admin_otso_panalo_imports')->insert(['source' => 'otso-test', 'report' => '{}', 'verified_at' => now()]);
    }

    private function otsoAdmin(?array $permissions = null): User
    {
        $user = User::factory()->create(['status' => 'Active']);
        AdminProfile::create(['user_id' => $user->id, 'legacy_admin_id' => $user->id, 'source_checksum' => str_repeat('a', 64), 'legacy_payload' => ['fixture' => true]]);
        $permissions ??= ['Otso Panalo', 'Create Otso Panalo', 'Update Otso Panalo', 'Otso Panalo Game Settings',
            'Otso Panalo Game Result', 'Otso Panalo Create Result', 'Otso Panalo Update Result', 'Otso Panalo Approve Result',
            'Otso Panalo Game Winners', 'Otso Panalo Game Winner Approval', 'Otso Panalo Game Types',
            'Otso Panalo Update Game Types', 'Ending Game PDF Generation'];
        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
            $user->givePermissionTo($permission);
        }
        $this->actingAs($user)->withSession(['admin_version' => $user->profile->session_version]);

        return $user;
    }

    private function otsoGame(array $overrides = []): int
    {
        $ending = DB::table('ending_games')->insertGetId(['team_a' => 1, 'team_b' => 2, 'schedule' => '2026-11-02 10:00:00', 'created_at' => now(), 'legacy_checksum' => str_repeat('a', 64)]);

        return DB::table('otso_panalo_games')->insertGetId($overrides + ['ending_game_id' => $ending, 'game_config_id' => 1,
            'jackpot_prize' => '1000.00', 'second_prize' => '100.00', 'third_prize' => '50.00', 'fourth_prize' => '10.00',
            'bet_amount' => '20.00', 'pool_percentage' => '20.00', 'betting_deadline' => '2026-11-02 09:30:00', 'created_at' => now()]);
    }

    private function otsoBet(int $game, int $matches = 4, array $overrides = []): int
    {
        $data = [];
        foreach (['first', 'second', 'third', 'fourth'] as $index => $quarter) {
            $data[$quarter.'_quarter_combination'] = $index < $matches ? ($index + 1).'-'.($index + 2) : '0-0';
        }

        return DB::table('otso_panalo_bets')->insertGetId($overrides + $data + ['game_id' => $game, 'player_id' => 9,
            'bet_type_id' => 1, 'name' => 'Ticket', 'amount' => '20.00', 'created_at' => now()]);
    }

    private function otsoApprovedResult(int $game): void
    {
        DB::table('otso_panalo_game_results')->insert(['game_id' => $game, 'status' => 'Approved',
            'first_quarter_result' => '1-2', 'second_quarter_result' => '2-3', 'third_quarter_result' => '3-4', 'fourth_quarter_result' => '4-5']);
        DB::table('otso_panalo_games')->where('id', $game)->update(['game_status_id' => 3]);
    }
}
