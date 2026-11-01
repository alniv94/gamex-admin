<?php

use App\Models\AdminProfile;
use App\Models\User;
use App\Services\Auth\AdminPermissions;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\Support\PlayerFixtures;

require __DIR__.'/../vendor/autoload.php';
$expected = str_replace('\\', '/', dirname(__DIR__).'/storage/framework/testing/admin-browser.sqlite');
$actual = str_replace('\\', '/', (string) getenv('DB_DATABASE'));
if (getenv('ADMIN_BROWSER_TEST') !== '1' || getenv('DB_CONNECTION') !== 'sqlite' || $actual !== $expected) {
    throw new RuntimeException('Browser fixtures require the isolated browser SQLite database.');
}
if (! is_dir(dirname($expected))) {
    mkdir(dirname($expected), 0755, true);
}
if (file_exists($expected)) {
    unlink($expected);
}
touch($expected);
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (DB::connection()->getDriverName() !== 'sqlite' || str_replace('\\', '/', DB::connection()->getDatabaseName()) !== $expected
    || config('admin.sms.driver') !== 'capture') {
    throw new RuntimeException('Unsafe browser fixture configuration.');
}
Artisan::call('migrate', ['--force' => true]);
Storage::disk('local')->deleteDirectory('browser-notifications');
$playerFixture = new class
{
    use PlayerFixtures;

    public function seed(): void
    {
        $this->playerFixtures();
    }
};
$playerFixture->seed();
Schema::table('player_wallet_transactions', function (Blueprint $table): void {
    $table->unsignedBigInteger('player_wallet_id')->nullable();
    $table->integer('reference_id')->default(0);
});
DB::table('player_wallet_transaction_types')->insert(['id' => 2, 'wallet_transaction_type' => 'Withdraw']);
DB::table('admin_player_cashier_imports')->insert(['source' => config('players.legacy_source'), 'report' => '{}', 'verified_at' => now()]);
for ($index = 1; $index <= 3; $index++) {
    $playerId = 2000 + $index;
    DB::table('players')->insert(['id' => $playerId, 'name' => 'Cashier Player '.$index, 'mobile_number' => '+63919988888'.$index, 'status' => 'Active', 'cashin' => 'Enabled', 'cashout' => 'Enabled']);
    DB::table('player_profiles')->insert(['player_id' => $playerId, 'kyc_status' => 'Verified']);
    DB::table('player_wallet')->insert(['player_id' => $playerId, 'actual_amount' => '123.45']);
    foreach (['cashin', 'cashout'] as $kind) {
        DB::table('player_'.$kind)->insert(['id' => 900 + $index, 'player_id' => $playerId, 'bank_id' => 1, 'amount' => '25.10', 'created_at' => '2026-10-15 16:00:00']);
    }
}
DB::table('players')->where('id', 9)->update(['name' => 'Browser Test Player']);
for ($index = 1; $index <= 3; $index++) {
    foreach ([1000, 1100] as $offset) {
        DB::table('players')->insert(['id' => $offset + $index, 'name' => 'Browser Player '.$offset.' '.$index, 'mobile_number' => '+63919999999'.$index, 'email' => 'browser'.$offset.$index.'@example.test', 'status' => 'Active', 'created_at' => now()]);
        DB::table('player_profiles')->insert(['player_id' => $offset + $index, 'first_name' => 'Browser', 'last_name' => 'Player', 'birthdate' => '1997-08-13', 'kyc_status' => 'Files Submitted']);
    }
    DB::table('admin_player_fund_accounts')->insert([
        'id' => $index, 'player_id' => 1000 + $index, 'bank_id' => 1,
        'account_number' => '912345678'.$index, 'account_name' => 'Browser Player 1000 '.$index,
        'created_at' => '2026-10-15 16:00:00',
    ]);
    DB::table('player_wallet')->insert(['player_id' => 1000 + $index, 'actual_amount' => '100.25']);
    DB::table('player_wallet_transactions')->insert(['player_id' => 1000 + $index, 'transaction_type_id' => 1, 'description' => 'Browser wallet transaction', 'amount' => '100.25', 'created_at' => now()]);
    DB::table('admin_player_logs')->insert(['player_id' => 1000 + $index, 'action' => 'login', 'details' => 'Browser player logged in', 'created_at' => now()]);
}
DB::table('ending_leagues')->insert(['id' => 1, 'league' => 'Test League']);
DB::table('ending_teams')->insert([['id' => 1, 'league_id' => 1, 'team_name' => 'Blue Team'], ['id' => 2, 'league_id' => 1, 'team_name' => 'Red Team']]);
foreach ([1 => 'Started', 2 => 'End of 1st Quarter', 3 => 'End of 2nd Quarter', 4 => 'End of 3rd Quarter', 5 => 'Finished'] as $id => $status) {
    DB::table('ending_game_statuses')->insert(compact('id', 'status'));
}
for ($a = 0; $a < 10; $a++) {
    for ($b = 0; $b < 10; $b++) {
        DB::table('ending_slots')->insert(['id' => $a * 10 + $b + 1, 'slot' => "$a-$b"]);
    }
}
DB::table('ending_per_quarter_bet_amount_config')->insert(['id' => 1, 'amount' => '15.00', 'card_quantity' => 2]);
foreach (['1st', '2nd', '3rd', '4th'] as $quarter) {
    DB::table('ending_config')->insert(['config_name' => "Per Quarter Card - $quarter Quarter Prize Percent", 'config_value' => $quarter === '4th' ? '40' : '10']);
}
DB::table('admin_ending_imports')->insert(['source' => config('ending.legacy_source'), 'report' => '{}', 'verified_at' => now()]);
for ($i = 1; $i <= 6; $i++) {
    $user = User::factory()->create([
        'name' => 'Test Administrator '.$i, 'mobile_number' => '+6391'.$i.'1234567',
        'email' => 'admin'.$i.'@example.test', 'status' => 'Active',
    ]);
    AdminProfile::create([
        'user_id' => $user->id, 'legacy_admin_id' => $user->id,
        'legacy_payload' => ['test_fixture' => true], 'source_checksum' => str_repeat('a', 64),
    ]);
    foreach (['Player List', 'Player Verify', 'Player Settings', 'Ending Games', 'Ending Teams', 'Ending Leagues', 'Ending Config', 'Ending Game Create', 'Ending Game Settings', 'Ending Game Bet/Cards Creation', 'Ending Game Result Creation', 'Ending Game Winners', 'Ending Game Winner Verification', 'Ending Game Winner Approval 1'] as $permission) {
        $user->givePermissionTo($permission);
    }
    DB::table('ending_games')->insert(['id' => 100 + $i, 'team_a' => 1, 'team_b' => 2, 'schedule' => now()->subHour(), 'created_at' => now()]);
    DB::table('ending_bet_amounts')->insert(['id' => 200 + $i, 'game_id' => 100 + $i, 'bet_type_id' => 2, 'amount' => '15.00']);
    foreach ([1, 2, 3, 4] as $quarter) {
        DB::table('ending_bet_amount_prizes')->insert(['bet_amount_id' => 200 + $i, 'quarter_id' => $quarter, 'cash_prize' => '150.00']);
    }
    DB::table('ending_cards')->insert(['id' => 300 + $i, 'bet_amount_id' => 200 + $i]);
    DB::table('admin_ending_logs')->insert([
        'game_id' => 100 + $i, 'user_id' => $user->id, 'action' => 'add_card', 'subject_type' => 'bet_amount',
        'subject_id' => 200 + $i, 'before' => null, 'after' => json_encode(['quantity' => 1], JSON_THROW_ON_ERROR), 'created_at' => now()->subHour(),
    ]);
    DB::table('ending_bets')->insert(['id' => 400 + $i, 'player_id' => 9, 'card_id' => 300 + $i, 'slot_id' => 11, 'quarter_id' => 0]);
}
for ($index = 7; $index <= 9; $index++) {
    $user = User::factory()->create(['name' => 'Player Test Administrator '.$index, 'mobile_number' => '+6391'.$index.'1234567', 'email' => 'player-admin'.$index.'@example.test', 'status' => 'Active']);
    AdminProfile::create(['user_id' => $user->id, 'legacy_admin_id' => $user->id, 'legacy_payload' => ['test_fixture' => true], 'source_checksum' => str_repeat('a', 64)]);
    foreach (['Player List', 'Player Verify', 'Player Settings', 'Cash In', 'Cash Out', 'Cash In Approve', 'Cash In Dispprove', 'Cash In Manual', 'Cash Out Approve', 'Cash Out Dispprove', 'Cash Out Manual', 'Cashin Verify', 'Cashout Verify'] as $permission) {
        $user->givePermissionTo($permission);
    }
}
$accessManager = Role::create(['name' => 'Access manager', 'guard_name' => 'web']);
$accessManager->givePermissionTo([AdminPermissions::VIEW_USERS, AdminPermissions::MANAGE_USERS]);
for ($index = 1; $index <= 3; $index++) {
    $user = User::factory()->create(['name' => 'Settings Administrator '.$index, 'mobile_number' => '+63920123456'.$index, 'email' => 'settings-admin'.$index.'@example.test', 'status' => 'Active']);
    $user->profile()->create([]);
    $user->assignRole($accessManager);
}

echo 'Isolated browser fixtures ready.'.PHP_EOL;
