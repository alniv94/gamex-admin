<?php

use App\Models\AdminProfile;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
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
for ($i = 1; $i <= 3; $i++) {
    $user = User::factory()->create([
        'name' => 'Test Administrator '.$i, 'mobile_number' => '+6391'.$i.'1234567',
        'email' => 'admin'.$i.'@example.test', 'status' => 'Active',
    ]);
    AdminProfile::create([
        'user_id' => $user->id, 'legacy_admin_id' => $user->id,
        'legacy_payload' => ['test_fixture' => true], 'source_checksum' => str_repeat('a', 64),
    ]);
}
echo 'Isolated browser fixtures ready.'.PHP_EOL;
