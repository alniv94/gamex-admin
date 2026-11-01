<?php

namespace Tests\Support;

use App\Models\AdminProfile;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;

trait EndingFixtures
{
    private function endingFixtures(): void
    {
        config(['ending.legacy_source' => 'gamexsports']);
        Schema::create('players', function (Blueprint $t): void {
            $t->id();
            $t->string('name')->nullable();
        });
        Schema::create('legacy_player_mappings', function (Blueprint $t): void {
            $t->id();
            $t->string('source');
            $t->unsignedBigInteger('legacy_player_id');
            $t->unsignedBigInteger('player_id')->nullable();
            $t->boolean('quarantined')->default(false);
        });
        DB::table('players')->insert(['id' => 9, 'name' => 'Test Player']);
        DB::table('legacy_player_mappings')->insert([
            ['id' => 100, 'source' => 'gamexsports', 'legacy_player_id' => 73, 'player_id' => 9, 'quarantined' => false],
            ['id' => 101, 'source' => 'gamexsports', 'legacy_player_id' => 74, 'player_id' => null, 'quarantined' => true],
        ]);
        foreach ([1 => 'Started', 2 => 'End of 1st Quarter', 3 => 'End of 2nd Quarter', 4 => 'End of 3rd Quarter', 5 => 'Finished'] as $id => $status) {
            DB::table('ending_game_statuses')->insert(compact('id', 'status'));
        }
        foreach ([1 => '1st', 2 => '2nd', 3 => '3rd', 4 => '4th'] as $id => $quarter) {
            DB::table('ending_game_quarters')->insert(compact('id', 'quarter'));
        }
        DB::table('ending_bet_types')->insert([['id' => 1, 'type' => 'Traditional'], ['id' => 2, 'type' => 'Per Quarter']]);
        DB::table('ending_game_winner_statuses')->insert([['id' => 1, 'status' => 'Verified'], ['id' => 2, 'status' => 'Approved']]);
        for ($a = 0; $a < 10; $a++) {
            for ($b = 0; $b < 10; $b++) {
                DB::table('ending_slots')->insert(['id' => $a * 10 + $b + 1, 'slot' => "$a-$b"]);
            }
        }
        DB::table('ending_leagues')->insert(['id' => 1, 'league' => 'Test League']);
        DB::table('ending_teams')->insert([
            ['id' => 1, 'league_id' => 1, 'team_name' => 'Blue Team'], ['id' => 2, 'league_id' => 1, 'team_name' => 'Red Team'],
            ['id' => 3, 'league_id' => 2, 'team_name' => 'Other League'],
        ]);
        DB::table('ending_per_quarter_bet_amount_config')->insert(['id' => 1, 'amount' => '15.00', 'card_quantity' => 2]);
        foreach (['1st', '2nd', '3rd', '4th'] as $quarter) {
            DB::table('ending_config')->insert(['config_name' => "Per Quarter Card - $quarter Quarter Prize Percent", 'config_value' => $quarter === '4th' ? '40' : '10']);
        }
        DB::table('admin_ending_imports')->insert(['source' => 'gamexsports', 'report' => '{}', 'verified_at' => now()]);
    }

    private function endingAdmin(array $permissions = [17, 32, 10, 12, 11, 34, 8, 9]): User
    {
        $user = User::factory()->create(['status' => 'Active']);
        AdminProfile::create(['user_id' => $user->id, 'legacy_admin_id' => $user->id, 'source_checksum' => str_repeat('a', 64), 'legacy_payload' => ['fixture' => true]]);
        $names = [
            1 => 'Player List',
            2 => 'Cash In',
            3 => 'Cash Out',
            8 => 'Ending Game Winner Verification',
            9 => 'Ending Game Winner Approval 1',
            10 => 'Ending Game Settings',
            11 => 'Ending Game Result Creation',
            12 => 'Ending Game Bet/Cards Creation',
            15 => 'Users',
            17 => 'Ending Games',
            18 => 'Ending Teams',
            19 => 'Ending Leagues',
            20 => 'Ending Config',
            23 => 'Player Verify',
            24 => 'Player Settings',
            26 => 'Cash In Approve',
            27 => 'Cash In Dispprove',
            28 => 'Cash In Manual',
            29 => 'Cash Out Approve',
            30 => 'Cash Out Dispprove',
            31 => 'Cash Out Manual',
            32 => 'Ending Game Create',
            34 => 'Ending Game Winners',
            52 => 'Cashin Verify',
            53 => 'Cashout Verify',
        ];
        foreach ($permissions as $id) {
            Permission::findOrCreate($names[$id], 'web');
            $user->givePermissionTo($names[$id]);
        }
        $this->actingAs($user)->withSession(['admin_version' => $user->profile->session_version]);

        return $user;
    }
}
