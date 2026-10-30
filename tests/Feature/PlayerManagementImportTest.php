<?php

namespace Tests\Feature;

use App\Services\Legacy\PlayerManagementImport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PDO;
use Tests\Support\PlayerFixtures;
use Tests\TestCase;

class PlayerManagementImportTest extends TestCase
{
    use PlayerFixtures, RefreshDatabase;

    private string $fixture;

    private PDO $source;

    private function sourceFixtures(): void
    {
        $this->playerFixtures();
        foreach (PlayerManagementImport::TABLES as $target) {
            DB::table($target)->delete();
        }
        DB::table('admin_player_imports')->delete();
        $this->fixture = tempnam(sys_get_temp_dir(), 'player-management-');
        $this->source = new PDO('sqlite:'.$this->fixture);
        $records = [
            'players' => [
                ['id' => 73089, 'account_verified' => 'Files Submitted', 'betting' => 'Disabled', 'cashin' => 'Enabled', 'cashout' => 'Disabled', 'is_active' => 'Active'],
                ['id' => 73090, 'account_verified' => 'Verified', 'betting' => 'Enabled', 'cashin' => '', 'cashout' => 'Enabled', 'is_active' => 'Active'],
            ],
            'player_verification_details' => [['player_id' => 73089, 'processing_by' => 1, 'processing_at' => '0000-00-00 00:00:00', 'player_update' => 'No', 'verified_by' => 1, 'verified_at' => '2026-10-16 16:00:00']],
            'player_reject_reasons' => [['id' => 1, 'reason' => 'Unclear ID', 'category' => 'ID', 'is_active' => 'Yes', 'created_at' => '0000-00-00 00:00:00', 'updated_at' => null]],
            'banks' => [['id' => 1, 'bank' => 'GCash', 'type' => null, 'image' => null, 'is_active' => 'Active', 'for_add_token' => 'Yes', 'for_withdrawal' => 'Yes', 'created_at' => null, 'updated_at' => null]],
            'bank_accounts' => [['id' => 1, 'player_id' => 73089, 'bank_id' => 1, 'account_number' => '9123456789', 'account_name' => 'Example Player', 'is_verified' => 'Pending', 'is_default' => 'No', 'is_active' => 'Active', 'created_at' => '2026-10-16 16:00:00', 'updated_at' => null]],
            'admin_user_logs' => [
                ['id' => 1, 'player_id' => 73089, 'user_id' => 1, 'action' => 'verified_player', 'reference_id' => 0, 'details' => 'Historical evaluation', 'reject_reason' => null, 'remarks' => null, 'ip_address' => '127.0.0.1', 'created_at' => '2026-10-16 16:00:00'],
                ['id' => 2, 'player_id' => 0, 'user_id' => 1, 'action' => 'login', 'reference_id' => 0, 'details' => 'Unrelated admin action', 'reject_reason' => null, 'remarks' => null, 'ip_address' => '127.0.0.1', 'created_at' => null],
            ],
            'player_logs' => [
                ['id' => 1, 'player_id' => 73089, 'action' => 'login', 'reference_id' => 0, 'details' => 'Player logged in', 'ip_address' => '127.0.0.1', 'created_at' => '2026-10-16 16:00:00'],
                ['id' => 2, 'player_id' => 73090, 'action' => 'login', 'reference_id' => 0, 'details' => 'Quarantined identity', 'ip_address' => '127.0.0.1', 'created_at' => null],
            ],
        ];
        foreach ($records as $table => $rows) {
            $key = $table === 'player_verification_details' ? 'player_id' : 'id';
            $columns = array_map(fn (string $column) => $column === $key ? $column.' INTEGER PRIMARY KEY' : $column.' TEXT', array_keys($rows[0]));
            $this->source->exec('CREATE TABLE '.$table.' ('.implode(',', $columns).')');
            $statement = $this->source->prepare('INSERT INTO '.$table.' VALUES ('.implode(',', array_fill(0, count($rows[0]), '?')).')');
            foreach ($rows as $row) {
                $statement->execute(array_values($row));
            }
        }
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

    public function test_dry_run_does_not_write_player_controls_or_history(): void
    {
        $this->sourceFixtures();

        $this->artisan('legacy:player-management', ['--dry-run' => true, '--batch' => 1])->assertSuccessful();

        $this->assertDatabaseCount('admin_player_legacy_states', 0);
        $this->assertDatabaseCount('admin_player_logs', 0);
        $this->assertDatabaseHas('players', ['id' => 9, 'betting' => null]);
    }

    public function test_copy_remaps_identities_preserves_quarantine_and_converts_dates_without_replaying_wallets(): void
    {
        $this->sourceFixtures();

        $this->artisan('legacy:player-management', ['--execute' => true, '--batch' => 1])->assertSuccessful();

        $this->assertDatabaseHas('admin_player_verifications', ['player_id' => 9, 'legacy_player_id' => 73089, 'verified_at' => '2026-10-16 08:00:00', 'processing_at' => null]);
        $this->assertDatabaseHas('admin_player_fund_accounts', ['player_id' => 9, 'legacy_player_id' => 73089, 'account_number' => '9123456789']);
        $this->assertDatabaseHas('admin_player_banks', ['id' => 1, 'type' => null]);
        $this->assertDatabaseHas('admin_player_logs', ['id' => 2, 'legacy_player_id' => 73090, 'player_id' => null]);
        $this->assertDatabaseMissing('admin_player_legacy_logs', ['id' => 2]);
        $this->assertDatabaseHas('players', ['id' => 9, 'betting' => 'Disabled', 'cashin' => 'Enabled', 'cashout' => 'Disabled']);
        $this->assertDatabaseHas('player_wallet', ['player_id' => 9, 'actual_amount' => '123.45']);
        $this->assertDatabaseCount('players', 3);
        $this->assertDatabaseCount('player_wallet_transactions', 1);
        $this->assertSame(2, (int) $this->source->query('SELECT COUNT(*) FROM player_logs')->fetchColumn());
        $this->artisan('legacy:player-management', ['--execute' => true])->assertSuccessful();
        $this->assertDatabaseCount('admin_player_logs', 2);
    }

    public function test_rerunning_import_does_not_overwrite_live_admin_decisions(): void
    {
        $this->sourceFixtures();
        $this->artisan('legacy:player-management', ['--execute' => true])->assertSuccessful();
        DB::table('players')->where('id', 9)->update(['betting' => 'Enabled']);
        DB::table('admin_player_fund_accounts')->where('id', 1)->update(['is_verified' => 'Verified', 'is_default' => 'Yes']);
        DB::table('admin_player_verifications')->where('player_id', 9)->update(['verified_by' => 2, 'verified_at' => '2026-10-30 08:00:00']);

        $this->artisan('legacy:player-management', ['--execute' => true])->assertSuccessful();

        $this->assertDatabaseHas('players', ['id' => 9, 'betting' => 'Enabled']);
        $this->assertDatabaseHas('admin_player_fund_accounts', ['id' => 1, 'is_verified' => 'Verified', 'is_default' => 'Yes']);
        $this->assertDatabaseHas('admin_player_verifications', ['player_id' => 9, 'verified_by' => 2]);
    }

    public function test_changed_source_or_target_history_is_reported_without_overwriting(): void
    {
        $this->sourceFixtures();
        $this->artisan('legacy:player-management', ['--execute' => true])->assertSuccessful();
        $this->source->exec("UPDATE player_logs SET details = 'Changed source' WHERE id = 1");

        $this->artisan('legacy:player-management', ['--verify' => true])->assertFailed();
        $this->assertDatabaseHas('admin_player_logs', ['id' => 1, 'details' => 'Player logged in']);
        $this->source->exec("UPDATE player_logs SET details = 'Player logged in' WHERE id = 1");
        DB::table('admin_player_logs')->where('id', 1)->update(['details' => 'Changed target']);
        $this->artisan('legacy:player-management', ['--verify' => true])->assertFailed();
        $this->assertDatabaseHas('admin_player_logs', ['id' => 1, 'details' => 'Changed target']);
    }

    public function test_missing_mappings_and_invalid_modes_fail_before_copying(): void
    {
        $this->sourceFixtures();
        $this->artisan('legacy:player-management', ['--execute' => true, '--verify' => true])->assertFailed();
        config(['players.legacy_source' => 'different-source']);
        $this->artisan('legacy:player-management', ['--execute' => true])->assertFailed();

        $this->assertDatabaseCount('admin_player_logs', 0);
    }

    public function test_one_missing_identity_mapping_refuses_to_guess_the_player(): void
    {
        $this->sourceFixtures();
        DB::table('legacy_player_mappings')->where('legacy_player_id', 73090)->delete();

        $this->artisan('legacy:player-management', ['--execute' => true])->assertFailed();

        $this->assertDatabaseCount('admin_player_legacy_states', 0);
        $this->assertDatabaseCount('admin_player_logs', 0);
    }
}
