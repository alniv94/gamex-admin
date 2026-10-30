<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PDO;
use Tests\Support\PlayerFixtures;
use Tests\TestCase;

class PlayerCashierImportTest extends TestCase
{
    use PlayerFixtures, RefreshDatabase;

    private ?string $fixture = null;

    private ?PDO $source = null;

    private function sourceFixtures(): void
    {
        $this->playerFixtures();
        $this->fixture = tempnam(sys_get_temp_dir(), 'player-cashier-');
        $this->source = new PDO('sqlite:'.$this->fixture);
        foreach (['player_cashin', 'player_cashout'] as $table) {
            $this->source->exec('CREATE TABLE '.$table.' (id INTEGER PRIMARY KEY, player_id INTEGER, amount TEXT, status TEXT, type TEXT, created_at TEXT, evaluated_at TEXT, evaluated_by INTEGER, remarks TEXT)');
            $insert = $this->source->prepare('INSERT INTO '.$table.' VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $insert->execute([71, 73089, '100.25', 'Approved', $table === 'player_cashin' ? 'Paymaya Service' : 'Cash Out', '2026-10-16 00:00:00', '0000-00-00 00:00:00', 47, 'Historical approval']);
            $insert->execute([72, 73090, '25.10', 'Pending', 'Manual', null, null, 0, null]);
        }
        config(['database.connections.legacy' => ['driver' => 'sqlite', 'database' => $this->fixture, 'prefix' => '', 'foreign_key_constraints' => true]]);
        DB::purge('legacy');
    }

    public function test_dry_run_reports_missing_records_without_changing_target(): void
    {
        $this->sourceFixtures();

        $this->artisan('legacy:player-cashier', ['--dry-run' => true])->assertSuccessful();

        $this->assertDatabaseCount('player_cashin', 0);
        $this->assertDatabaseCount('player_cashout', 0);
        $this->assertDatabaseCount('admin_player_cashier_imports', 0);
    }

    public function test_import_preserves_ids_remaps_players_quarantines_dates_and_never_replays_balances(): void
    {
        $this->sourceFixtures();

        $this->artisan('legacy:player-cashier', ['--execute' => true, '--batch' => 1])->assertSuccessful();

        $this->assertDatabaseHas('player_cashin', ['id' => 71, 'legacy_player_id' => 73089, 'player_id' => 9, 'created_at' => '2026-10-15 16:00:00', 'evaluated_at' => null, 'status' => 'Approved']);
        $this->assertDatabaseHas('player_cashout', ['id' => 72, 'legacy_player_id' => 73090, 'player_id' => null, 'status' => 'Pending']);
        $this->assertSame(['evaluated_at' => '0000-00-00 00:00:00'], json_decode(DB::table('player_cashin')->where('id', 71)->value('legacy_dates'), true));
        $this->assertDatabaseHas('player_wallet', ['player_id' => 9, 'actual_amount' => '123.45']);
        $this->assertDatabaseCount('player_wallet_transactions', 1);
        $this->assertDatabaseCount('admin_player_cashier_imports', 1);
        $this->assertSame(2, (int) $this->source->query('SELECT COUNT(*) FROM player_cashin')->fetchColumn());

        $this->artisan('legacy:player-cashier', ['--execute' => true])->assertSuccessful();
        $this->assertDatabaseCount('player_cashin', 2);
        $this->assertDatabaseCount('player_cashout', 2);
    }

    public function test_reruns_preserve_local_evaluations_and_refuse_changed_source(): void
    {
        $this->sourceFixtures();
        $this->artisan('legacy:player-cashier', ['--execute' => true])->assertSuccessful();
        DB::table('player_cashout')->where('id', 72)->update(['status' => 'Rejected', 'remarks' => 'Local decision']);

        $this->artisan('legacy:player-cashier', ['--verify' => true])->assertSuccessful();
        $this->assertDatabaseHas('player_cashout', ['id' => 72, 'status' => 'Rejected', 'remarks' => 'Local decision']);

        $this->source->exec("UPDATE player_cashin SET amount = '900.00' WHERE id = 71");
        $this->artisan('legacy:player-cashier', ['--execute' => true])->assertFailed();
        $this->assertDatabaseHas('player_cashin', ['id' => 71, 'amount' => '100.25']);
    }

    public function test_changed_target_history_and_missing_mappings_abort_without_overwriting(): void
    {
        $this->sourceFixtures();
        $this->artisan('legacy:player-cashier', ['--execute' => true])->assertSuccessful();
        DB::table('player_cashin')->where('id', 71)->update(['amount' => '999.00']);

        $this->artisan('legacy:player-cashier', ['--verify' => true])->assertFailed();
        $this->assertDatabaseHas('player_cashin', ['id' => 71, 'amount' => '999.00']);

        DB::table('legacy_player_mappings')->delete();
        $this->artisan('legacy:player-cashier', ['--dry-run' => true])->assertFailed();
        $this->assertDatabaseCount('player_wallet_transactions', 1);
    }

    public function test_verify_detects_missing_history_and_modes_are_mutually_exclusive(): void
    {
        $this->sourceFixtures();

        $this->artisan('legacy:player-cashier', ['--verify' => true])->assertFailed();
        $this->artisan('legacy:player-cashier', ['--execute' => true, '--dry-run' => true])->assertFailed();
        $this->assertDatabaseCount('player_cashin', 0);
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
}
