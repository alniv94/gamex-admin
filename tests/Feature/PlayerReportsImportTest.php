<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PDO;
use Tests\TestCase;

class PlayerReportsImportTest extends TestCase
{
    use RefreshDatabase;

    private ?string $fixture = null;

    private ?PDO $source = null;

    public function test_dry_run_does_not_write_and_import_is_verified_and_rerunnable(): void
    {
        $this->sourceFixtures();

        $this->artisan('legacy:player-reports', ['--dry-run' => true])->assertSuccessful();
        $this->assertDatabaseCount('lucky_four_bets', 0);
        $this->assertDatabaseCount('admin_player_report_imports', 0);
        $this->artisan('legacy:player-reports', ['--execute' => true, '--batch' => 1])->assertSuccessful();
        $this->assertDatabaseHas('lucky_four_bets', ['id' => 10, 'ending_bet_id' => 1, 'amount' => '12.50']);
        $this->assertDatabaseHas('admin_player_report_imports', ['source' => config('players.legacy_source'), 'row_count' => 1]);
        $this->artisan('legacy:player-reports', ['--execute' => true])->assertSuccessful();
        $this->artisan('legacy:player-reports', ['--verify' => true])->assertSuccessful();
        $this->assertDatabaseCount('lucky_four_bets', 1);
        $this->assertSame(1, (int) $this->source->query('SELECT COUNT(*) FROM lucky_four_bets')->fetchColumn());
    }

    public function test_import_refuses_missing_or_native_parent_bets(): void
    {
        $this->sourceFixtures();
        DB::table('ending_bets')->where('id', 1)->update(['legacy_checksum' => null]);

        $this->artisan('legacy:player-reports', ['--execute' => true])->assertFailed();
        $this->assertDatabaseCount('lucky_four_bets', 0);
        DB::table('ending_bets')->where('id', 1)->delete();
        $this->artisan('legacy:player-reports', ['--execute' => true])->assertFailed();
        $this->assertDatabaseCount('admin_player_report_imports', 0);
    }

    public function test_import_refuses_source_or_target_drift_without_overwriting_amounts(): void
    {
        $this->sourceFixtures();
        $this->artisan('legacy:player-reports', ['--execute' => true])->assertSuccessful();
        $this->source->exec('UPDATE lucky_four_bets SET amount = 99 WHERE id = 10');

        $this->artisan('legacy:player-reports', ['--execute' => true])->assertFailed();
        $this->assertDatabaseHas('lucky_four_bets', ['id' => 10, 'amount' => '12.50']);
        $this->source->exec('UPDATE lucky_four_bets SET amount = 12.50 WHERE id = 10');
        DB::table('lucky_four_bets')->where('id', 10)->update(['amount' => '20.00']);
        $this->artisan('legacy:player-reports', ['--verify' => true])->assertFailed();
    }

    public function test_verification_rejects_missing_records_and_invalid_command_options(): void
    {
        $this->sourceFixtures();

        $this->artisan('legacy:player-reports', ['--verify' => true])->assertFailed();
        $this->artisan('legacy:player-reports', ['--execute' => true, '--dry-run' => true])->assertFailed();
        $this->artisan('legacy:player-reports', ['--batch' => 0])->assertFailed();
        $this->assertDatabaseCount('lucky_four_bets', 0);
    }

    private function sourceFixtures(): void
    {
        $this->fixture = tempnam(sys_get_temp_dir(), 'player-reports-');
        $this->source = new PDO('sqlite:'.$this->fixture);
        $this->source->exec('CREATE TABLE lucky_four_bets (id INTEGER PRIMARY KEY, ending_bet_id INTEGER, amount DECIMAL)');
        $this->source->exec('INSERT INTO lucky_four_bets VALUES (10, 1, 12.50)');
        config(['database.connections.legacy' => ['driver' => 'sqlite', 'database' => $this->fixture, 'prefix' => '']]);
        DB::purge('legacy');
        DB::table('ending_bets')->insert(['id' => 1, 'player_id' => 9, 'card_id' => 1, 'slot_id' => 1, 'legacy_checksum' => str_repeat('a', 64)]);
    }

    protected function tearDown(): void
    {
        DB::disconnect('legacy');
        $this->source = null;
        if ($this->fixture !== null) {
            unlink($this->fixture);
        }
        parent::tearDown();
    }
}
