<?php

namespace Tests\Feature;

use App\Services\Legacy\InvestorImport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PDO;
use Tests\Support\PlayerFixtures;
use Tests\TestCase;

class InvestorImportTest extends TestCase
{
    use PlayerFixtures, RefreshDatabase;

    private string $fixture;

    private PDO $source;

    private function sourceFixtures(): void
    {
        $this->playerFixtures();
        $this->fixture = tempnam(sys_get_temp_dir(), 'investor-import-');
        $this->source = new PDO('sqlite:'.$this->fixture);
        $records = [
            'outlets' => [['id' => 1, 'outlet_name' => 'Test outlet']],
            'investors' => [['id' => 1, 'investor_type_id' => 1, 'first_name' => 'Test', 'last_name' => 'Investor',
                'mobile_number' => '9123456789', 'password' => 'existing-hash', 'login_key' => 'expired-session',
                'wallet' => '123.45', 'status' => 'Active', 'created_at' => '2026-11-01 08:00:00']],
            'investor_sub_agent_players' => [
                ['id' => 1, 'investor_agent_id' => 1, 'player_id' => 73089, 'commission' => '2.50'],
                ['id' => 2, 'investor_agent_id' => 1, 'player_id' => 73090, 'commission' => '0.00'],
            ],
            'investor_payouts' => [['id' => 1, 'investor_id' => 1, 'player_id' => 73089, 'amount' => '10.15', 'status' => 'Pending', 'is_settled' => 'Yes', 'created_at' => '0000-00-00 00:00:00']],
            'investor_commissions' => [['id' => 1, 'investor_id' => 1, 'player_id' => 73089, 'bet_id' => 100, 'commission_amount' => '2.50', 'commission_percent' => '1.00', 'is_settled' => 'No', 'is_paid' => 'No']],
            'investor_wallet_movements' => [['id' => 1, 'investor_id' => 1, 'transfer_to' => 73090, 'type' => 'Player', 'token' => '5.25', 'created_at' => '2026-11-01 08:00:00']],
            'investor_downline_settled_commission' => [
                ['id' => 1, 'investor_id' => 1, 'requestor_id' => 1, 'type' => 'Agent', 'amount' => '0.25'],
                ['id' => 2, 'investor_id' => 1, 'requestor_id' => 73089, 'type' => 'Player', 'amount' => '0.10'],
            ],
            'investor_logs' => [['id' => 1, 'investor_id' => 1, 'details' => 'Historical log', 'ip_address' => '127.0.0.1', 'created_at' => null]],
        ];
        foreach ([...InvestorImport::TABLES, 'ending_bets'] as $table) {
            $rows = $table === 'ending_bets' ? [['id' => 100, 'created_at' => '2026-11-01 08:00:00']] : ($records[$table] ?? []);
            $columns = $rows ? array_keys($rows[0]) : ['id'];
            $this->source->exec('CREATE TABLE '.$table.' ('.implode(',', array_map(fn ($column) => $column.($column === 'id' ? ' INTEGER PRIMARY KEY' : ' TEXT'), $columns)).')');
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

    public function test_dry_run_does_not_copy_accounts_or_record_a_verified_import(): void
    {
        $this->sourceFixtures();

        $this->artisan('legacy:investors')->assertSuccessful();

        $this->assertDatabaseCount('admin_investors', 0);
        $this->assertDatabaseCount('admin_investor_imports', 0);
    }

    public function test_import_preserves_balances_remaps_players_quarantines_contacts_and_converts_dates(): void
    {
        $this->sourceFixtures();

        $this->artisan('legacy:investors', ['--execute' => true, '--batch' => 1])->assertSuccessful();

        $this->assertDatabaseHas('admin_investors', ['id' => 1, 'wallet' => '123.45', 'password' => 'existing-hash', 'created_at' => '2026-11-01 00:00:00']);
        $this->assertDatabaseHas('admin_investor_sub_agent_players', ['id' => 1, 'player_id' => 9, 'legacy_player_id' => 73089]);
        $this->assertDatabaseHas('admin_investor_sub_agent_players', ['id' => 2, 'player_id' => null, 'legacy_player_id' => 73090]);
        $this->assertDatabaseHas('admin_investor_payouts', ['id' => 1, 'created_at' => null, 'status' => 'Pending', 'is_settled' => 'Yes']);
        $this->assertDatabaseHas('admin_investor_commissions', ['id' => 1, 'player_id' => 9, 'bet_created_at' => '2026-11-01 00:00:00']);
        $this->assertDatabaseHas('admin_investor_wallet_movements', ['id' => 1, 'transfer_to' => null, 'legacy_transfer_to' => 73090]);
        $this->assertDatabaseHas('admin_investor_downline_settled_commission', ['id' => 1, 'requestor_id' => 1, 'type' => 'Agent']);
        $this->assertDatabaseHas('admin_investor_downline_settled_commission', ['id' => 2, 'requestor_id' => 9, 'legacy_requestor_id' => 73089, 'type' => 'Player']);
        $this->assertDatabaseHas('player_wallet', ['player_id' => 9, 'actual_amount' => '123.45']);
        $this->assertDatabaseCount('player_wallet_transactions', 1);
        $this->assertSame('expired-session', $this->source->query('SELECT login_key FROM investors')->fetchColumn());
    }

    public function test_reruns_verify_history_without_reverting_live_account_or_settlement_changes(): void
    {
        $this->sourceFixtures();
        $this->artisan('legacy:investors', ['--execute' => true])->assertSuccessful();
        DB::table('admin_investors')->where('id', 1)->update(['status' => 'Inactive', 'wallet' => '999.99']);
        DB::table('admin_investor_commissions')->where('id', 1)->update(['is_settled' => 'Yes', 'settlement_id' => 20]);
        DB::table('admin_investors')->insert(['id' => 200, 'first_name' => 'Local account']);

        $this->artisan('legacy:investors', ['--execute' => true])->assertSuccessful();
        $this->artisan('legacy:investors', ['--verify' => true])->assertSuccessful();

        $this->assertDatabaseCount('admin_investors', 2);
        $this->assertDatabaseHas('admin_investors', ['id' => 1, 'wallet' => '999.99', 'status' => 'Inactive']);
        $this->assertDatabaseHas('admin_investor_commissions', ['id' => 1, 'settlement_id' => 20]);
    }

    public function test_missing_mapping_aborts_before_any_copy(): void
    {
        $this->sourceFixtures();
        DB::table('legacy_player_mappings')->where('legacy_player_id', 73090)->delete();

        $this->artisan('legacy:investors', ['--execute' => true])->assertFailed();

        $this->assertDatabaseCount('admin_outlets', 0);
        $this->assertDatabaseCount('admin_investors', 0);
    }

    public function test_identity_collision_aborts_instead_of_adopting_a_local_account(): void
    {
        $this->sourceFixtures();
        DB::table('admin_investors')->insert(['id' => 1, 'first_name' => 'Local']);

        $this->artisan('legacy:investors', ['--execute' => true])->assertFailed();

        $this->assertDatabaseCount('admin_outlets', 0);
        $this->assertDatabaseHas('admin_investors', ['id' => 1, 'first_name' => 'Local']);
    }

    public function test_changed_source_and_tampered_history_fail_verification_without_overwriting(): void
    {
        $this->sourceFixtures();
        $this->artisan('legacy:investors', ['--execute' => true])->assertSuccessful();
        $this->source->exec("UPDATE investors SET wallet = '5.00' WHERE id = 1");

        $this->artisan('legacy:investors', ['--verify' => true])->assertFailed();
        $this->assertDatabaseHas('admin_investors', ['id' => 1, 'wallet' => '123.45']);
        $this->source->exec("UPDATE investors SET wallet = '123.45' WHERE id = 1");
        DB::table('admin_investor_logs')->where('id', 1)->update(['details' => 'Modified']);

        $this->artisan('legacy:investors', ['--verify' => true])->assertFailed();
        $this->assertDatabaseHas('admin_investor_logs', ['id' => 1, 'details' => 'Modified']);
    }

    public function test_conflicting_modes_are_rejected(): void
    {
        $this->artisan('legacy:investors', ['--execute' => true, '--verify' => true])->assertFailed();
        $this->assertDatabaseCount('admin_investors', 0);
    }
}
