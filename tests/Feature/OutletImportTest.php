<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PDO;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class OutletImportTest extends TestCase
{
    use RefreshDatabase;

    private ?string $fixture = null;

    private ?PDO $source = null;

    private function sourceFixtures(): void
    {
        Schema::create('barangays', function (Blueprint $table): void {
            $table->id();
        });
        DB::table('barangays')->insert(['id' => 5590]);
        $this->fixture = tempnam(sys_get_temp_dir(), 'outlet-import-');
        $this->source = new PDO('sqlite:'.$this->fixture);
        $this->source->exec('CREATE TABLE outlets (id INTEGER PRIMARY KEY, outlet_name TEXT, barangay_id INTEGER, address TEXT, is_active TEXT, created_at TEXT)');
        $this->source->exec("INSERT INTO outlets VALUES (1, 'Las Casas Bagac Bataan', 5590, 'Hotel de Oriente bldg', 'Active', '2022-05-18 11:00:23')");
        $this->source->exec("INSERT INTO outlets VALUES (2, 'Historical outlet', NULL, NULL, 'Inactive', '0000-00-00 00:00:00')");
        config(['database.connections.legacy' => ['driver' => 'sqlite', 'database' => $this->fixture], 'players.legacy_source' => 'test-outlets']);
        DB::purge('legacy');
    }

    protected function tearDown(): void
    {
        DB::disconnect('legacy');
        $this->source = null;
        if ($this->fixture !== null && is_file($this->fixture)) {
            unlink($this->fixture);
        }
        parent::tearDown();
    }

    public function test_dry_run_reports_missing_outlets_without_writing(): void
    {
        $this->sourceFixtures();

        $this->artisan('legacy:outlets', ['--dry-run' => true])->expectsOutputToContain('source=2 matching=0 missing=2')->assertSuccessful();

        $this->assertDatabaseCount('admin_outlets', 0);
        $this->assertDatabaseCount('admin_outlet_imports', 0);
    }

    public function test_imports_and_verifies_outlets_idempotently_preserving_ids_dates_and_nulls(): void
    {
        $this->sourceFixtures();

        $this->artisan('legacy:outlets', ['--execute' => true, '--batch' => 1])->assertSuccessful();
        $this->artisan('legacy:outlets', ['--execute' => true])->assertSuccessful();
        $this->artisan('legacy:outlets', ['--verify' => true])->assertSuccessful();

        $this->assertDatabaseCount('admin_outlets', 2);
        $this->assertDatabaseHas('admin_outlets', ['id' => 1, 'outlet_name' => 'Las Casas Bagac Bataan',
            'barangay_id' => 5590, 'created_at' => '2022-05-18 03:00:23', 'management_version' => 0]);
        $this->assertDatabaseHas('admin_outlets', ['id' => 2, 'is_active' => 'Inactive', 'created_at' => null,
            'barangay_id' => null, 'address' => null, 'legacy_dates' => '{"created_at":"0000-00-00 00:00:00"}']);
        $this->assertSame('2022-05-18 11:00:23', $this->source->query('SELECT created_at FROM outlets WHERE id=1')->fetchColumn());
    }

    public function test_reruns_retain_managed_edits_and_locally_created_outlets(): void
    {
        $this->sourceFixtures();
        $this->artisan('legacy:outlets', ['--execute' => true])->assertSuccessful();
        DB::table('admin_outlets')->where('id', 1)->update(['outlet_name' => 'Renamed outlet', 'is_active' => 'Inactive', 'management_version' => 1]);
        DB::table('admin_outlets')->insert(['outlet_name' => 'Local outlet', 'management_version' => 1]);

        $this->artisan('legacy:outlets', ['--execute' => true])->assertSuccessful();
        $this->artisan('legacy:outlets', ['--verify' => true])->assertSuccessful();

        $this->assertDatabaseCount('admin_outlets', 3);
        $this->assertDatabaseHas('admin_outlets', ['id' => 1, 'outlet_name' => 'Renamed outlet', 'is_active' => 'Inactive']);
    }

    public function test_local_id_collision_aborts_before_any_import_and_retains_local_data(): void
    {
        $this->sourceFixtures();
        DB::table('admin_outlets')->insert(['id' => 2, 'outlet_name' => 'Local outlet']);

        $this->artisan('legacy:outlets', ['--execute' => true, '--batch' => 1])->assertFailed();

        $this->assertDatabaseCount('admin_outlets', 1);
        $this->assertDatabaseHas('admin_outlets', ['id' => 2, 'outlet_name' => 'Local outlet']);
        $this->assertDatabaseCount('admin_outlet_imports', 0);
    }

    public function test_verification_detects_changed_source_and_unmanaged_target_data(): void
    {
        $this->sourceFixtures();
        $this->artisan('legacy:outlets', ['--execute' => true])->assertSuccessful();
        $this->source->exec("UPDATE outlets SET address='Source changed' WHERE id=1");

        $this->artisan('legacy:outlets', ['--verify' => true])->assertFailed();
        $this->assertDatabaseHas('admin_outlets', ['id' => 1, 'address' => 'Hotel de Oriente bldg']);
        $this->source->exec("UPDATE outlets SET address='Hotel de Oriente bldg' WHERE id=1");
        DB::table('admin_outlets')->where('id', 1)->update(['address' => 'Unmanaged change']);
        $this->artisan('legacy:outlets', ['--verify' => true])->assertFailed();
    }

    public function test_missing_geography_aborts_without_copying_partial_data(): void
    {
        $this->sourceFixtures();
        DB::table('barangays')->delete();

        $this->artisan('legacy:outlets', ['--execute' => true])->assertFailed();

        $this->assertDatabaseCount('admin_outlets', 0);
    }

    public function test_verification_detects_missing_target_and_removed_source_rows(): void
    {
        $this->sourceFixtures();
        $this->artisan('legacy:outlets', ['--verify' => true])->assertFailed();
        $this->artisan('legacy:outlets', ['--execute' => true])->assertSuccessful();
        $this->source->exec('DELETE FROM outlets WHERE id=2');

        $this->artisan('legacy:outlets', ['--verify' => true])->assertFailed();

        $this->assertDatabaseCount('admin_outlets', 2);
    }

    public function test_different_source_identity_is_rejected(): void
    {
        $this->sourceFixtures();
        DB::table('admin_outlet_imports')->insert(['source' => 'different-source', 'report' => '{}', 'verified_at' => now()]);

        $this->artisan('legacy:outlets', ['--execute' => true])->assertFailed();

        $this->assertDatabaseCount('admin_outlets', 0);
    }

    #[TestWith([['--execute' => true, '--verify' => true]])]
    #[TestWith([['--batch' => 0]])]
    #[TestWith([['--batch' => 5001]])]
    public function test_invalid_command_options_are_rejected(array $options): void
    {
        $this->artisan('legacy:outlets', $options)->assertFailed();

        $this->assertDatabaseCount('admin_outlets', 0);
    }
}
