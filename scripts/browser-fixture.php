<?php

use App\Models\AdminProfile;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

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
foreach ([17, 32, 10, 12, 11, 34, 8, 9] as $permission) {
    DB::table('admin_permissions')->insert(['id' => $permission, 'name' => 'Test permission '.$permission, 'module_order' => $permission]);
}
Schema::create('players', function (Blueprint $t): void {
    $t->id();
    $t->string('name');
});
DB::table('players')->insert(['id' => 9, 'name' => 'Browser Test Player']);
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
    foreach ([17, 32, 10, 12, 11, 34, 8, 9] as $permission) {
        DB::table('admin_user_permissions')->insert(['user_id' => $user->id, 'permission_id' => $permission]);
    }
    DB::table('ending_games')->insert(['id' => 100 + $i, 'team_a' => 1, 'team_b' => 2, 'schedule' => now()->subHour(), 'created_at' => now()]);
    DB::table('ending_bet_amounts')->insert(['id' => 200 + $i, 'game_id' => 100 + $i, 'bet_type_id' => 2, 'amount' => '15.00']);
    foreach ([1, 2, 3, 4] as $quarter) {
        DB::table('ending_bet_amount_prizes')->insert(['bet_amount_id' => 200 + $i, 'quarter_id' => $quarter, 'cash_prize' => '150.00']);
    }
    DB::table('ending_cards')->insert(['id' => 300 + $i, 'bet_amount_id' => 200 + $i]);
    DB::table('ending_bets')->insert(['id' => 400 + $i, 'player_id' => 9, 'card_id' => 300 + $i, 'slot_id' => 11, 'quarter_id' => 0]);
}
echo 'Isolated browser fixtures ready.'.PHP_EOL;
