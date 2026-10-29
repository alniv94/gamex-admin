<?php

namespace Tests\Feature;

use App\Services\Legacy\EndingImport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PDO;
use RuntimeException;
use Tests\Support\EndingFixtures;
use Tests\TestCase;

class EndingImportTest extends TestCase
{
    use EndingFixtures, RefreshDatabase;

    private string $fixture;

    private PDO $source;

    protected function setUp(): void
    {
        parent::setUp();
        $this->endingFixtures();
        DB::table('ending_games')->insert(['id' => 42, 'team_a' => 1, 'team_b' => 2, 'schedule' => '2025-01-02 18:00:00', 'game_status_id' => 5, 'prize_released' => 'Yes', 'created_at' => '0000-00-00 00:00:00']);
        DB::table('ending_bet_amounts')->insert(['id' => 51, 'game_id' => 42, 'amount' => '15.00', 'bet_type_id' => 2]);
        DB::table('ending_bet_amount_prizes')->insert(['id' => 61, 'bet_amount_id' => 51, 'quarter_id' => 1, 'is_cash' => 'Cash', 'cash_prize' => '150.00']);
        DB::table('ending_cards')->insert(['id' => 71, 'bet_amount_id' => 51, 'prize_released' => 'Yes']);
        DB::table('ending_bets')->insert([
            ['id' => 81, 'player_id' => 73, 'card_id' => 71, 'slot_id' => 11, 'quarter_id' => 0, 'result' => 'Win', 'name' => 'Historical ticket'],
            ['id' => 82, 'player_id' => 74, 'card_id' => 71, 'slot_id' => 12, 'quarter_id' => 0, 'result' => 'Lose', 'name' => null],
        ]);
        DB::table('ending_game_results')->insert(['id' => 91, 'game_id' => 42, 'quarter_id' => 1, 'team_a_score' => 21, 'team_b_score' => 30, 'slot_id' => 11]);
        DB::table('ending_game_winners')->insert(['id' => 101, 'bet_id' => 81, 'quarter_id' => 1, 'winner_status_id' => 2, 'prize_released' => 'Yes']);
        DB::table('ending_agent_bet_commissions')->insert(['bet_id' => 81, 'agent_amount' => '1.25', 'total_amount' => '2.50', 'is_paid' => 'Yes']);
        DB::table('admin_ending_legacy_logs')->insert([
            ['id' => 111, 'user_id' => 0, 'action' => 'approve_winner', 'reference_id' => 101, 'details' => 'Approve Winner', 'ip_address' => '127.0.0.1', 'created_at' => '2025-01-02 18:00:00'],
            ['id' => 112, 'user_id' => 0, 'action' => 'approve_winner', 'reference_id' => 101, 'details' => 'Approve Lucky Four Winner', 'ip_address' => '127.0.0.1', 'created_at' => null],
            ['id' => 113, 'user_id' => 999, 'action' => 'unverify_winner', 'reference_id' => 999, 'details' => 'Unverify Winner', 'ip_address' => '127.0.0.1', 'created_at' => null],
            ['id' => 114, 'user_id' => 0, 'action' => 'set_is_lucky_four', 'reference_id' => 42, 'details' => 'Set is lucky four to Yes.', 'ip_address' => '127.0.0.1', 'created_at' => null],
        ]);
        $this->fixture = tempnam(sys_get_temp_dir(), 'ending-source-');
        $this->source = new PDO('sqlite:'.$this->fixture);
        // Mirror the source's actual columns, excluding new provenance and workflow metadata.
        foreach (EndingImport::TABLES as $table) {
            $target = $table === 'admin_user_logs' ? 'admin_ending_legacy_logs' : $table;
            $sql = DB::table('sqlite_master')->where('type', 'table')->where('name', $target)->value('sql');
            $this->source->exec(str_replace('"'.$target.'"', '"'.$table.'"', $sql));
            $exclude = ['legacy_checksum', 'legacy_dates'];
            $exclude = array_merge($exclude, match ($table) {
                'ending_bets' => ['legacy_player_id', 'legacy_player_mapping_id'],
                'ending_game_results' => ['updated_at'],
                'ending_game_winners' => ['verified_by', 'approved_by', 'approved_at'],
                'admin_user_logs' => ['game_id'], default => [],
            });
            foreach ($exclude as $column) {
                $this->source->exec("ALTER TABLE $table DROP COLUMN $column");
            }
            foreach (DB::table($target)->get() as $record) {
                $values = array_diff_key((array) $record, array_flip($exclude));
                $statement = $this->source->prepare('INSERT INTO '.$table.' ('.implode(',', array_keys($values)).') VALUES ('.implode(',', array_fill(0, count($values), '?')).')');
                $statement->execute(array_values($values));
            }
            DB::table($target)->delete();
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

    public function test_dry_run_reads_all_relations_without_writing(): void
    {
        $this->artisan('legacy:ending', ['--dry-run' => true, '--batch' => 1])->assertSuccessful();
        $this->assertDatabaseCount('ending_games', 0);
        $this->assertDatabaseCount('ending_bets', 0);
        $this->assertSame(2, (int) $this->source->query('SELECT COUNT(*) FROM ending_bets')->fetchColumn());
    }

    public function test_complete_copy_preserves_history_remaps_players_and_is_rerunnable(): void
    {
        $this->artisan('legacy:ending', ['--execute' => true, '--batch' => 1])->assertSuccessful();
        $this->assertDatabaseHas('ending_games', ['id' => 42, 'schedule' => '2025-01-02 10:00:00', 'created_at' => null, 'prize_released' => 'Yes']);
        $this->assertSame(['created_at' => '0000-00-00 00:00:00'], json_decode(DB::table('ending_games')->value('legacy_dates'), true));
        $this->assertDatabaseHas('ending_bets', ['id' => 81, 'player_id' => 9, 'legacy_player_id' => 73, 'legacy_player_mapping_id' => 100, 'name' => 'Historical ticket']);
        $this->assertDatabaseHas('ending_bets', ['id' => 82, 'player_id' => null, 'legacy_player_id' => 74, 'legacy_player_mapping_id' => 101]);
        $this->assertDatabaseHas('ending_game_winners', ['id' => 101, 'bet_id' => 81, 'winner_status_id' => 2, 'prize_released' => 'Yes', 'approved_at' => null]);
        $this->assertDatabaseHas('ending_agent_bet_commissions', ['bet_id' => 81, 'agent_amount' => '1.25', 'total_amount' => '2.50', 'is_paid' => 'Yes']);
        $this->assertDatabaseHas('admin_ending_legacy_logs', ['id' => 111, 'game_id' => 42, 'user_id' => 0, 'created_at' => '2025-01-02 10:00:00']);
        $this->assertDatabaseHas('admin_ending_legacy_logs', ['id' => 113, 'game_id' => null, 'user_id' => 999]);
        $this->assertDatabaseMissing('admin_ending_legacy_logs', ['id' => 112]);
        $this->assertDatabaseHas('admin_ending_legacy_logs', ['id' => 114, 'game_id' => 42, 'action' => 'set_is_lucky_four']);
        $this->artisan('legacy:ending', ['--execute' => true])->assertSuccessful();
        $this->artisan('legacy:ending', ['--verify' => true])->assertSuccessful();
        $this->assertDatabaseCount('ending_bets', 2);
        $this->assertDatabaseCount('ending_game_winners', 1);
        $this->assertDatabaseCount('players', 1);
        $this->assertDatabaseCount('users', 0);
    }

    public function test_existing_native_identity_conflict_is_never_overwritten(): void
    {
        DB::table('ending_games')->insert(['id' => 42, 'team_a' => 2, 'team_b' => 1]);
        $this->artisan('legacy:ending', ['--execute' => true])->assertFailed();
        $this->assertDatabaseHas('ending_games', ['id' => 42, 'team_a' => 2, 'team_b' => 1, 'legacy_checksum' => null]);
        $this->assertDatabaseCount('ending_bets', 0);
    }

    public function test_zero_date_archive_verifies_after_mysql_style_json_key_reordering(): void
    {
        $this->source->exec("UPDATE ending_games SET enabled_betting_at = '0000-00-00 00:00:00' WHERE id = 42");
        $this->artisan('legacy:ending', ['--execute' => true])->assertSuccessful();
        DB::table('ending_games')->where('id', 42)->update(['legacy_dates' => json_encode([
            'created_at' => '0000-00-00 00:00:00', 'enabled_betting_at' => '0000-00-00 00:00:00',
        ])]);
        $this->artisan('legacy:ending', ['--verify' => true])->assertSuccessful();
    }

    public function test_source_and_target_drift_fail_without_overwriting_imported_data(): void
    {
        $import = app(EndingImport::class);
        $import->scan(true, false, 10);
        $this->source->exec("UPDATE ending_bets SET name = 'Changed source ticket' WHERE id = 81");
        $this->artisan('legacy:ending', ['--execute' => true])->assertFailed();
        $this->assertDatabaseHas('ending_bets', ['id' => 81, 'name' => 'Historical ticket']);
        $this->source->exec("UPDATE ending_bets SET name = 'Historical ticket' WHERE id = 81");
        DB::table('ending_bets')->where('id', 81)->update(['name' => 'Changed local ticket']);
        $this->artisan('legacy:ending', ['--verify' => true])->assertFailed();
    }

    public function test_source_connection_rejects_writes_and_missing_player_mapping_is_not_guessed(): void
    {
        try {
            DB::connection('legacy')->table('ending_games')->where('id', 42)->delete();
            $this->fail('Expected a read-only source refusal.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('read-only', $exception->getMessage());
        }
        DB::table('legacy_player_mappings')->where('legacy_player_id', 73)->delete();
        $this->artisan('legacy:ending', ['--execute' => true])->assertFailed();
        $this->assertDatabaseCount('ending_bets', 0);
    }

    public function test_approved_identity_resolution_can_attach_quarantined_bets_with_an_audit(): void
    {
        $this->artisan('legacy:ending', ['--execute' => true])->assertSuccessful();
        $this->artisan('ending:link-players', ['--execute' => true])->assertSuccessful();
        $this->assertDatabaseHas('ending_bets', ['id' => 82, 'player_id' => null]);
        DB::table('players')->insert(['id' => 10, 'name' => 'Reviewed Player']);
        DB::table('legacy_player_mappings')->where('id', 101)->update(['player_id' => 10]);
        $this->artisan('ending:link-players', ['--execute' => true])->assertSuccessful();
        $this->assertDatabaseHas('ending_bets', ['id' => 82, 'player_id' => null]);
        DB::table('legacy_player_mappings')->where('id', 101)->update(['quarantined' => false]);
        $this->artisan('ending:link-players', ['--dry-run' => true])->assertSuccessful();
        $this->assertDatabaseHas('ending_bets', ['id' => 82, 'player_id' => null]);
        $this->artisan('ending:link-players', ['--execute' => true])->assertSuccessful();
        $this->artisan('ending:link-players', ['--execute' => true])->assertSuccessful();
        $this->assertDatabaseHas('ending_bets', ['id' => 82, 'player_id' => 10, 'legacy_player_id' => 74, 'result' => 'Lose']);
        $this->assertDatabaseHas('admin_ending_logs', ['game_id' => 42, 'subject_id' => 82, 'action' => 'link_resolved_player']);
        $this->assertDatabaseCount('admin_ending_logs', 1);
        $this->artisan('legacy:ending', ['--verify' => true])->assertSuccessful();
    }
}
