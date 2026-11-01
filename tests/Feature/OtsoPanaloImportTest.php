<?php

namespace Tests\Feature;

use App\Services\Legacy\OtsoPanaloImport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PDO;
use RuntimeException;
use Tests\Support\OtsoPanaloFixtures;
use Tests\TestCase;

class OtsoPanaloImportTest extends TestCase
{
    use OtsoPanaloFixtures, RefreshDatabase;

    private string $fixture;

    private PDO $source;

    private function sourceFixture(): void
    {
        $this->otsoFixtures();
        $game = $this->otsoGame();
        $bet = $this->otsoBet($game);
        $this->otsoApprovedResult($game);
        DB::table('otso_panalo_winners')->insert(['bet_id' => $bet, 'winner_type_id' => 1, 'winner_status' => 'Approved', 'prize_released' => 'Yes', 'prize_amount' => '1000.00', 'created_at' => '2026-04-15 10:00:00']);
        DB::table('admin_otso_panalo_legacy_logs')->insert(['user_id' => 77, 'game_id' => $game, 'action' => 'approve_otso_panalo_result', 'reference_id' => 1, 'details' => 'Approved Otso Panalo result.', 'created_at' => '2026-04-15 10:00:00']);
        $this->fixture = tempnam(sys_get_temp_dir(), 'otso-source-');
        $this->source = new PDO('sqlite:'.$this->fixture);
        foreach (OtsoPanaloImport::TABLES as $table) {
            $target = $table === 'admin_user_logs' ? 'admin_otso_panalo_legacy_logs' : $table;
            $columns = [];
            foreach (Schema::getColumns($target) as $column) {
                $name = $column['name'];
                if (str_starts_with($name, 'legacy_') || in_array($name, ['managed_at', 'winners_generated_at', 'version', 'created_by', 'approved_by', 'approved_at'], true)
                    || ($table === 'admin_user_logs' && $name === 'game_id') || ($table === 'otso_panalo_game_results' && $name === 'updated_at')) {
                    continue;
                }
                $columns[$name] = $name === 'id' ? 'INTEGER PRIMARY KEY' : $column['type_name'];
            }
            $definitions = [];
            foreach ($columns as $name => $type) {
                $definitions[] = $name.' '.$type;
            }
            $this->source->exec('CREATE TABLE '.$table.' ('.implode(',', $definitions).')');
            foreach (DB::table($target)->get() as $row) {
                $data = array_intersect_key((array) $row, $columns);
                if ($table === 'otso_panalo_bets') {
                    $data['player_id'] = 73;
                }
                if ($table === 'otso_panalo_games') {
                    $data['created_at'] = '2026-04-15 10:00:00';
                    $data['updated_at'] = '0000-00-00 00:00:00';
                }
                $statement = $this->source->prepare('INSERT INTO '.$table.' ('.implode(',', array_keys($data)).') VALUES ('.implode(',', array_fill(0, count($data), '?')).')');
                $statement->execute(array_values($data));
            }
        }
        $this->source->exec('ALTER TABLE otso_panalo_games ADD COLUMN rollover_amount DECIMAL(10,2) DEFAULT 123.45');
        foreach (array_reverse(OtsoPanaloImport::TABLES) as $table) {
            DB::table($table === 'admin_user_logs' ? 'admin_otso_panalo_legacy_logs' : $table)->delete();
        }
        DB::table('admin_otso_panalo_imports')->delete();
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

    public function test_dry_run_reads_history_without_changing_target_or_wallet(): void
    {
        $this->sourceFixture();

        $this->artisan('legacy:otso-panalo', ['--dry-run' => true, '--batch' => 1])->assertSuccessful();

        $this->assertDatabaseCount('otso_panalo_games', 0);
        $this->assertDatabaseCount('otso_panalo_bets', 0);
        $this->assertDatabaseCount('admin_otso_panalo_imports', 0);
        $this->assertDatabaseCount('player_wallet_transactions', 0);
        $this->assertSame(1, (int) $this->source->query('SELECT COUNT(*) FROM otso_panalo_bets')->fetchColumn());
    }

    public function test_import_preserves_relationships_prizes_ids_dates_and_raw_rollover_history_without_replaying_payments(): void
    {
        $this->sourceFixture();

        $this->artisan('legacy:otso-panalo', ['--execute' => true, '--batch' => 1])->assertSuccessful();
        $this->artisan('legacy:otso-panalo', ['--execute' => true])->assertSuccessful();
        $this->artisan('legacy:otso-panalo', ['--verify' => true])->assertSuccessful();

        $this->assertDatabaseHas('otso_panalo_games', ['id' => 1, 'legacy_id' => 1, 'jackpot_prize' => '1000.00', 'created_at' => '2026-04-15 02:00:00', 'updated_at' => null]);
        $this->assertDatabaseHas('otso_panalo_bets', ['id' => 1, 'legacy_id' => 1, 'player_id' => 9, 'legacy_player_id' => 73, 'legacy_player_mapping_id' => 100, 'game_id' => 1]);
        $this->assertDatabaseHas('otso_panalo_winners', ['bet_id' => 1, 'winner_status' => 'Approved', 'prize_released' => 'Yes', 'prize_amount' => '1000.00']);
        $this->assertDatabaseHas('admin_otso_panalo_legacy_logs', ['legacy_id' => 1, 'game_id' => 1, 'user_id' => null, 'created_at' => '2026-04-15 02:00:00']);
        $archive = json_decode(DB::table('otso_panalo_games')->value('legacy_payload'), true);
        $this->assertSame(123.45, $archive['rollover_amount']);
        $this->assertFalse(Schema::hasColumn('otso_panalo_games', 'rollover_amount'));
        $this->assertNotNull(DB::table('otso_panalo_games')->value('winners_generated_at'));
        $this->assertDatabaseCount('otso_panalo_bets', 1);
        $this->assertDatabaseCount('player_wallet_transactions', 0);
        $this->assertDatabaseHas('player_wallet', ['actual_amount' => '100.00']);
    }

    public function test_verification_handles_mysql_json_key_order_and_rejects_target_and_source_drift(): void
    {
        $this->sourceFixture();
        $this->artisan('legacy:otso-panalo', ['--execute' => true])->assertSuccessful();
        $archive = json_decode(DB::table('otso_panalo_games')->value('legacy_payload'), true);
        DB::table('otso_panalo_games')->where('id', 1)->update(['legacy_payload' => json_encode(array_reverse($archive, true))]);

        $this->artisan('legacy:otso-panalo', ['--verify' => true])->assertSuccessful();
        DB::table('otso_panalo_bets')->where('id', 1)->update(['amount' => '21.00']);
        $this->artisan('legacy:otso-panalo', ['--verify' => true])->assertFailed();
        DB::table('otso_panalo_bets')->where('id', 1)->update(['amount' => '20.00']);
        $this->source->exec('UPDATE otso_panalo_bets SET amount = 22 WHERE id = 1');
        $this->artisan('legacy:otso-panalo', ['--execute' => true])->assertFailed();

        $this->assertDatabaseHas('otso_panalo_bets', ['id' => 1, 'amount' => '20.00']);
    }

    public function test_native_identity_collision_and_missing_player_mapping_are_never_guessed_or_overwritten(): void
    {
        $this->sourceFixture();
        DB::table('otso_panalo_statuses')->insert(['id' => 1, 'name' => 'Native status']);

        $this->artisan('legacy:otso-panalo', ['--execute' => true])->assertFailed();
        $this->assertDatabaseHas('otso_panalo_statuses', ['id' => 1, 'name' => 'Native status', 'legacy_id' => null]);
        DB::table('otso_panalo_statuses')->where('id', 1)->delete();
        DB::table('legacy_player_mappings')->where('legacy_player_id', 73)->delete();
        $this->artisan('legacy:otso-panalo', ['--execute' => true])->assertFailed();

        $this->assertDatabaseCount('otso_panalo_bets', 0);
        $this->assertDatabaseCount('player_wallet_transactions', 0);
        $this->assertDatabaseCount('admin_otso_panalo_imports', 0);
    }

    public function test_quarantined_player_is_preserved_unlinked_and_source_connection_rejects_writes(): void
    {
        $this->sourceFixture();
        $this->source->exec('UPDATE otso_panalo_bets SET player_id = 74 WHERE id = 1');
        $this->artisan('legacy:otso-panalo', ['--execute' => true])->assertSuccessful();

        $this->assertDatabaseHas('otso_panalo_bets', ['id' => 1, 'player_id' => null, 'legacy_player_id' => 74, 'legacy_player_mapping_id' => 101]);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('read-only');
        DB::connection('legacy')->table('otso_panalo_bets')->delete();
    }

    public function test_orphan_source_relationships_are_rejected_before_copying(): void
    {
        $this->sourceFixture();
        $this->source->exec('UPDATE otso_panalo_bets SET game_id = 999 WHERE id = 1');

        $this->artisan('legacy:otso-panalo', ['--execute' => true])->assertFailed();

        $this->assertDatabaseCount('otso_panalo_games', 0);
        $this->assertDatabaseCount('admin_otso_panalo_imports', 0);
    }

    public function test_missing_winner_catalog_uses_exact_legacy_seeder_definitions_only_in_the_target(): void
    {
        $this->sourceFixture();
        $this->source->exec('DELETE FROM otso_panalo_winner_types');

        $this->artisan('legacy:otso-panalo', ['--execute' => true])->assertSuccessful();
        $this->artisan('legacy:otso-panalo', ['--verify' => true])->assertSuccessful();

        $this->assertDatabaseHas('otso_panalo_winner_types', ['id' => 1, 'winner_type' => 'Jackpot', 'legacy_id' => null]);
        $this->assertDatabaseHas('otso_panalo_winner_types', ['id' => 4, 'winner_type' => 'Fourth Prize', 'legacy_id' => null]);
        $this->assertSame(0, (int) $this->source->query('SELECT COUNT(*) FROM otso_panalo_winner_types')->fetchColumn());
        $this->assertDatabaseCount('otso_panalo_winners', 1);
    }

    public function test_game_bet_import_is_idempotent_and_stops_after_result_processing(): void
    {
        $this->sourceFixture();
        $this->source->exec('DELETE FROM otso_panalo_winners');
        $this->source->exec('DELETE FROM otso_panalo_game_results');
        $this->source->exec('DELETE FROM admin_user_logs');
        $this->source->exec('UPDATE otso_panalo_games SET game_status_id = 1');
        $this->artisan('legacy:otso-panalo', ['--execute' => true])->assertSuccessful();
        $this->otsoAdmin();
        $this->source->exec("INSERT INTO otso_panalo_bets (id, player_id, game_id, bet_type_id, name, amount, refunded, added_to_prize, created_at) VALUES (2, 73, 1, 1, 'Additional legacy ticket', 20, 'No', 'No', '2026-11-01 08:00:00')");

        $this->post(route('otso.bets.import', 1))->assertSessionHasNoErrors();
        $this->post(route('otso.bets.import', 1))->assertSessionHasNoErrors();

        $this->assertDatabaseHas('otso_panalo_bets', ['id' => 2, 'legacy_id' => 2, 'player_id' => 9, 'name' => 'Additional legacy ticket']);
        $this->assertDatabaseCount('otso_panalo_bets', 2);
        $this->assertDatabaseCount('player_wallet_transactions', 0);
        $this->post(route('otso.results.store', 1), ['first_quarter_result' => '1-2'])->assertSessionHasNoErrors();
        $this->source->exec("INSERT INTO otso_panalo_bets (id, player_id, game_id, bet_type_id, amount, refunded) VALUES (3, 73, 1, 1, 20, 'No')");
        $this->post(route('otso.bets.import', 1))->assertSessionHasErrors('action');
        $this->assertDatabaseCount('otso_panalo_bets', 2);
    }
}
