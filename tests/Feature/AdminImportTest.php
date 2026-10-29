<?php

namespace Tests\Feature;

use App\Models\AdminProfile;
use App\Services\Legacy\AdminImport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PDO;
use RuntimeException;
use Tests\TestCase;

class AdminImportTest extends TestCase
{
    use RefreshDatabase;

    private string $fixture;

    private PDO $source;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixture = tempnam(sys_get_temp_dir(), 'admin-legacy-');
        $this->source = new PDO('sqlite:'.$this->fixture);
        $this->source->exec('CREATE TABLE admin_users (id INTEGER PRIMARY KEY, mobile_number TEXT, password TEXT, login_key TEXT, login_verified TEXT, is_active TEXT, created_at TEXT)');
        $this->source->exec('CREATE TABLE admin_user_info (user_id INTEGER PRIMARY KEY, first_name TEXT, middle_name TEXT, last_name TEXT, birthdate TEXT, email_address TEXT, role_id INTEGER)');
        $this->source->exec('CREATE TABLE admin_access (id INTEGER PRIMARY KEY, access TEXT, module_order INTEGER, vue_module TEXT)');
        $this->source->exec('CREATE TABLE admin_user_access (id INTEGER PRIMARY KEY, user_id INTEGER, access_id INTEGER)');
        $hash = password_hash('legacy-password', PASSWORD_BCRYPT, ['cost' => 4]);
        $statement = $this->source->prepare('INSERT INTO admin_users VALUES (?, ?, ?, ?, ?, ?, ?)');
        $statement->execute([7, '9171234567', $hash, 'old-secret-session', 'Verified', 'Active', '2025-01-02 10:00:00']);
        $statement->execute([8, '9181234567', '-', null, 'Not Verified', 'Inactive', '2025-02-03 10:00:00']);
        $this->source->exec("INSERT INTO admin_user_info VALUES (7, 'Active', null, 'Admin', '1990-01-01', 'shared@example.test', 1), (8, 'Inactive', 'M', 'Admin', '0000-00-00', 'shared@example.test', 2)");
        $this->source->exec("INSERT INTO admin_access VALUES (1, 'Dashboard', 1, 'Dashboard'), (2, 'Users', 2, 'Users')");
        $this->source->exec('INSERT INTO admin_user_access VALUES (1, 7, 1), (2, 7, 2), (3, 8, 1)');
        config(['database.connections.legacy' => ['driver' => 'sqlite', 'database' => $this->fixture, 'prefix' => '']]);
        DB::purge('legacy');
    }

    protected function tearDown(): void
    {
        DB::disconnect('legacy');
        unset($this->source);
        if (isset($this->fixture)) {
            unlink($this->fixture);
        }
        parent::tearDown();
    }

    public function test_dry_run_does_not_write_accounts(): void
    {
        $this->artisan('legacy:import-admins', ['--dry-run' => true])->assertSuccessful();
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('admin_profiles', 0);
        $this->assertSame(2, (int) $this->source->query('SELECT COUNT(*) FROM admin_users')->fetchColumn());
    }

    public function test_import_preserves_identity_hash_status_profile_and_permissions_and_is_idempotent(): void
    {
        $import = app(AdminImport::class);
        $plan = $import->plan();
        $import->execute($plan);
        $this->assertDatabaseHas('users', ['id' => 7, 'mobile_number' => '+639171234567', 'status' => 'Active', 'email' => null, 'created_at' => '2025-01-02 02:00:00']);
        $this->assertDatabaseHas('users', ['id' => 8, 'status' => 'Inactive', 'password' => '-']);
        $this->assertSame($plan['accounts'][7]['user']['password'], DB::table('users')->where('id', 7)->value('password'));
        $this->assertDatabaseHas('admin_profiles', ['user_id' => 8, 'birthdate' => null, 'legacy_role_id' => 2, 'email_address' => 'shared@example.test']);
        $profile = AdminProfile::where('legacy_admin_id', 7)->firstOrFail();
        $this->assertSame($plan['accounts'][7]['archive'], $profile->legacy_payload);
        $this->assertArrayNotHasKey('login_key', $profile->legacy_payload['user']);
        $this->assertStringNotContainsString('shared@example.test', $profile->getRawOriginal('legacy_payload'));
        $this->assertDatabaseCount('admin_user_permissions', 3);
        $import->execute($import->plan());
        $this->assertDatabaseCount('users', 2);
        $this->assertSame(2, $import->inspect($import->plan())['existing']);
        $this->artisan('legacy:verify-admins')->assertSuccessful();
    }

    public function test_target_collisions_abort_the_whole_import(): void
    {
        DB::table('users')->insert(['id' => 8, 'name' => 'Existing', 'password' => '!', 'status' => 'Active']);
        try {
            app(AdminImport::class)->execute(app(AdminImport::class)->plan());
            $this->fail('Expected a collision refusal.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('conflicts', $exception->getMessage());
        }
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('admin_profiles', 0);
        $this->assertDatabaseCount('admin_permissions', 0);
    }

    public function test_duplicate_normalized_mobiles_are_rejected(): void
    {
        $this->source->exec("UPDATE admin_users SET mobile_number='09171234567' WHERE id=8");
        $this->expectException(RuntimeException::class);
        app(AdminImport::class)->plan();
    }

    public function test_source_changes_are_detected_without_overwriting_imported_accounts(): void
    {
        $import = app(AdminImport::class);
        $import->execute($import->plan());
        $this->source->exec("UPDATE admin_users SET is_active='Inactive' WHERE id=7");
        $this->artisan('legacy:import-admins', ['--execute' => true])->assertFailed();
        $this->assertDatabaseHas('users', ['id' => 7, 'status' => 'Active']);
    }

    public function test_target_permission_drift_is_detected(): void
    {
        $import = app(AdminImport::class);
        $import->execute($import->plan());
        DB::table('admin_user_permissions')->where('user_id', 7)->where('permission_id', 2)->delete();
        $this->artisan('legacy:verify-admins')->assertFailed();
    }

    public function test_source_connection_rejects_writes(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('read-only');
        DB::connection('legacy')->table('admin_users')->where('id', 7)->update(['is_active' => 'Inactive']);
    }

    public function test_invalid_duplicate_and_orphaned_grants_are_preserved_without_granting_access(): void
    {
        $this->source->exec('INSERT INTO admin_user_access VALUES (4, 7, 0), (5, 7, 1), (6, 99, 2)');
        $import = app(AdminImport::class);
        $plan = $import->plan();
        $import->execute($plan);
        $report = $import->inspect($plan);
        $this->assertSame(1, $report['invalid_grants_archived']);
        $this->assertSame(1, $report['duplicate_grants_archived']);
        $this->assertSame(1, $report['orphan_rows_archived']);
        $this->assertDatabaseCount('admin_user_permissions', 3);
        $this->assertDatabaseCount('admin_legacy_exceptions', 1);
        $this->assertDatabaseMissing('admin_permissions', ['id' => 0]);
        $profile = AdminProfile::where('legacy_admin_id', 7)->firstOrFail();
        $this->assertCount(4, $profile->legacy_payload['permission_rows']);
        $this->artisan('legacy:verify-admins')->assertSuccessful();
        DB::table('admin_legacy_exceptions')->delete();
        $this->artisan('legacy:verify-admins')->assertFailed();
    }

    public function test_inactive_accounts_without_mobiles_are_preserved_but_active_ones_are_rejected(): void
    {
        $this->source->exec('UPDATE admin_users SET mobile_number=NULL WHERE id=8');
        $import = app(AdminImport::class);
        $import->execute($import->plan());
        $this->assertDatabaseHas('users', ['id' => 8, 'mobile_number' => null, 'status' => 'Inactive']);
        $this->source->exec('UPDATE admin_users SET mobile_number=NULL WHERE id=7');
        $this->expectException(RuntimeException::class);
        $import->plan();
    }
}
