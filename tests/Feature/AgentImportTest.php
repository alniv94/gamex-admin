<?php

namespace Tests\Feature;

use App\Services\Legacy\AgentImport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PDO;
use Tests\Support\PlayerFixtures;
use Tests\TestCase;

class AgentImportTest extends TestCase
{
    use PlayerFixtures, RefreshDatabase;

    private string $fixture;

    private PDO $source;

    private function sourceFixtures(): void
    {
        $this->playerFixtures();
        $this->fixture = tempnam(sys_get_temp_dir(), 'agent-import-');
        $this->source = new PDO('sqlite:'.$this->fixture);
        $data = [
            'ending_agents' => [['id' => 8, 'username' => 'legacy-agent', 'password' => 'preserved-hash', 'login_key' => 'old-session', 'wallet' => '123.45', 'created_at' => '2026-11-01 08:00:00']],
            'ending_agent_info' => [['agent_id' => 8, 'first_name' => 'Test', 'last_name' => 'Agent', 'mobile_number' => '9123456780']],
            'ending_agent_players' => [
                ['player_id' => 73089, 'agent_id' => 8, 'commission' => '2.50', 'created_at' => '2026-11-01 08:00:00'],
                ['player_id' => 73090, 'agent_id' => 8, 'commission' => '0.00', 'created_at' => '0000-00-00 00:00:00'],
            ],
            'ending_agent_logs' => [['id' => 1, 'agent_id' => 8, 'player_id' => 73089, 'details' => 'Imported history', 'created_at' => '2026-11-01 08:00:00']],
            'ending_agent_cashin' => [['id' => 1, 'agent_id' => 8, 'amount' => '10.25', 'status' => 'Approved', 'evaluated_at' => '0000-00-00 00:00:00']],
        ];
        foreach (AgentImport::TABLES as $table) {
            $rows = $data[$table] ?? [];
            $columns = $rows ? array_keys($rows[0]) : ['id'];
            $this->source->exec('CREATE TABLE '.$table.' ('.implode(',', array_map(fn ($column) => $column.' TEXT', $columns)).')');
            foreach ($rows as $row) {
                $statement = $this->source->prepare('INSERT INTO '.$table.' VALUES ('.implode(',', array_fill(0, count($row), '?')).')');
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

    public function test_dry_run_reports_missing_data_without_importing(): void
    {
        $this->sourceFixtures();

        $this->artisan('legacy:agents')->assertSuccessful();

        $this->assertDatabaseCount('admin_ending_agents', 0);
        $this->assertDatabaseCount('admin_agent_imports', 0);
    }

    public function test_import_preserves_balances_and_history_maps_players_and_converts_manila_dates(): void
    {
        $this->sourceFixtures();

        $this->artisan('legacy:agents', ['--execute' => true, '--batch' => 1])->assertSuccessful();

        $this->assertDatabaseHas('admin_ending_agents', ['id' => 8, 'password' => 'preserved-hash', 'wallet' => '123.45', 'created_at' => '2026-11-01 00:00:00']);
        $this->assertDatabaseHas('admin_ending_agent_players', ['agent_id' => 8, 'player_id' => 9, 'legacy_player_id' => 73089]);
        $this->assertDatabaseHas('admin_ending_agent_players', ['agent_id' => 8, 'player_id' => null, 'legacy_player_id' => 73090, 'created_at' => null]);
        $this->assertDatabaseHas('admin_ending_agent_logs', ['player_id' => 9, 'legacy_player_id' => 73089, 'created_at' => '2026-11-01 00:00:00']);
        $this->assertDatabaseHas('admin_ending_agent_cashin', ['amount' => '10.25', 'status' => 'Approved', 'evaluated_at' => null]);
        $this->assertDatabaseHas('player_wallet', ['player_id' => 9, 'actual_amount' => '123.45']);
        $this->assertDatabaseCount('player_wallet_transactions', 1);
        $this->assertDatabaseCount('admin_agent_audits', 0);
        $this->assertSame('old-session', $this->source->query('SELECT login_key FROM ending_agents')->fetchColumn());
    }

    public function test_reruns_preserve_live_edits_detached_links_and_local_accounts(): void
    {
        $this->sourceFixtures();
        $this->artisan('legacy:agents', ['--execute' => true])->assertSuccessful();
        DB::table('admin_ending_agents')->where('id', 8)->update(['wallet' => '999.99', 'is_active' => 'Inactive']);
        DB::table('admin_ending_agent_info')->where('agent_id', 8)->update(['first_name' => 'Updated']);
        DB::table('admin_ending_agent_players')->where('player_id', 9)->update(['detached_at' => '2026-11-02 01:00:00']);
        DB::table('admin_ending_agents')->insert(['id' => 100, 'username' => 'local']);

        $this->artisan('legacy:agents', ['--execute' => true])->assertSuccessful();
        $this->artisan('legacy:agents', ['--verify' => true])->assertSuccessful();

        $this->assertDatabaseHas('admin_ending_agents', ['id' => 8, 'wallet' => '999.99', 'is_active' => 'Inactive']);
        $this->assertDatabaseHas('admin_ending_agent_info', ['agent_id' => 8, 'first_name' => 'Updated']);
        $this->assertDatabaseHas('admin_ending_agent_players', ['player_id' => 9, 'detached_at' => '2026-11-02 01:00:00']);
        $this->assertDatabaseCount('admin_ending_agents', 2);
        $this->assertDatabaseCount('admin_ending_agent_players', 2);
    }

    public function test_missing_player_mapping_aborts_before_copying_any_accounts(): void
    {
        $this->sourceFixtures();
        DB::table('legacy_player_mappings')->where('legacy_player_id', 73090)->delete();

        $this->artisan('legacy:agents', ['--execute' => true])->assertFailed();

        $this->assertDatabaseCount('admin_ending_agents', 0);
        $this->assertDatabaseCount('admin_agent_imports', 0);
    }

    public function test_duplicate_legacy_usernames_are_preserved_without_merging_accounts(): void
    {
        $this->sourceFixtures();
        $this->source->exec("INSERT INTO ending_agents (id, username, password, wallet) VALUES (9, 'legacy-agent', 'other-hash', '0.00')");

        $this->artisan('legacy:agents', ['--execute' => true])->assertSuccessful();
        $this->artisan('legacy:agents', ['--verify' => true])->assertSuccessful();

        $this->assertDatabaseCount('admin_ending_agents', 2);
        $this->assertDatabaseHas('admin_ending_agents', ['id' => 8, 'username' => 'legacy-agent', 'wallet' => '123.45']);
        $this->assertDatabaseHas('admin_ending_agents', ['id' => 9, 'username' => 'legacy-agent', 'wallet' => '0.00']);
    }

    public function test_local_identity_collision_is_not_adopted(): void
    {
        $this->sourceFixtures();
        DB::table('admin_ending_agents')->insert(['id' => 8, 'username' => 'local']);

        $this->artisan('legacy:agents', ['--execute' => true])->assertFailed();

        $this->assertDatabaseHas('admin_ending_agents', ['id' => 8, 'username' => 'local']);
        $this->assertDatabaseCount('admin_ending_agent_info', 0);
    }

    public function test_source_changes_and_tampered_history_fail_verification_without_overwriting(): void
    {
        $this->sourceFixtures();
        $this->artisan('legacy:agents', ['--execute' => true])->assertSuccessful();
        $this->source->exec("UPDATE ending_agents SET wallet = '1.00'");

        $this->artisan('legacy:agents', ['--verify' => true])->assertFailed();
        $this->assertDatabaseHas('admin_ending_agents', ['wallet' => '123.45']);
        $this->source->exec("UPDATE ending_agents SET wallet = '123.45'");
        DB::table('admin_ending_agent_logs')->where('id', 1)->update(['details' => 'Tampered']);

        $this->artisan('legacy:agents', ['--verify' => true])->assertFailed();
        $this->assertDatabaseHas('admin_ending_agent_logs', ['details' => 'Tampered']);
    }

    public function test_verification_fails_for_missing_rows_and_rejects_conflicting_modes(): void
    {
        $this->sourceFixtures();

        $this->artisan('legacy:agents', ['--verify' => true])->assertFailed();
        $this->artisan('legacy:agents', ['--execute' => true, '--dry-run' => true])->assertFailed();
        $this->artisan('legacy:agents', ['--batch' => 0])->assertFailed();
        $this->assertDatabaseCount('admin_agent_imports', 0);
    }
}
