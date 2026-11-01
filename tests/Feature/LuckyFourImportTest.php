<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PDO;
use Tests\Support\LuckyFourFixtures;
use Tests\TestCase;

class LuckyFourImportTest extends TestCase
{
    use LuckyFourFixtures, RefreshDatabase;

    private string $fixture;

    private PDO $source;

    private function sourceFixture(bool $partial = true): void
    {
        $this->luckyFixtures();
        $game = $this->luckyGame();
        $bet = $this->luckyBet($game);
        $this->fixture = tempnam(sys_get_temp_dir(), 'lucky-source-');
        $this->source = new PDO('sqlite:'.$this->fixture);
        $this->source->exec('CREATE TABLE lucky_four_prizes_settings (id INTEGER PRIMARY KEY, jackpot_prize DECIMAL(10,2), three_digits_prize DECIMAL(10,2), initial_amount DECIMAL(10,2), percentage_deduction DECIMAL(10,2), bet_amount DECIMAL(10,2))');
        $this->source->exec('CREATE TABLE lucky_four_prizes_setting_logs (id INTEGER PRIMARY KEY, prizes_setting_id INTEGER, jackpot_prize DECIMAL(10,2), three_digits_prize DECIMAL(10,2), initial_amount DECIMAL(10,2), percentage_deduction DECIMAL(10,2), bet_amount DECIMAL(10,2), created_at DATETIME)');
        $this->source->exec('CREATE TABLE lucky_four_prizes (id INTEGER PRIMARY KEY, game_id INTEGER, percentage_deduction DECIMAL(10,2), bet_amount DECIMAL(10,2), initial_amount DECIMAL(10,2), amount_added DECIMAL(10,2), three_digits_prize DECIMAL(10,2), jackpot_prize DECIMAL(10,2))');
        $this->source->exec('CREATE TABLE lucky_four_bets (id INTEGER PRIMARY KEY, ending_bet_id INTEGER UNIQUE, game_id INTEGER, combination CHAR(4), amount DECIMAL(10,2), is_added VARCHAR(16))');
        $this->source->exec('CREATE TABLE lucky_four_winners (id INTEGER PRIMARY KEY, ending_bet_id INTEGER UNIQUE, winner_status VARCHAR(16), prize_released VARCHAR(8), prize_amount DECIMAL(10,2), created_at DATETIME)');
        $this->source->exec('CREATE TABLE admin_user_logs (id INTEGER PRIMARY KEY, user_id INTEGER, action VARCHAR(100), reference_id INTEGER, details TEXT, created_at DATETIME, remarks TEXT)');
        $this->source->exec('CREATE TABLE ending_games (id INTEGER PRIMARY KEY)');
        $this->source->exec("INSERT INTO ending_games VALUES ({$game})");
        $this->source->exec('INSERT INTO lucky_four_prizes_settings VALUES (1,1000,50,800,40,20)');
        $this->source->exec("INSERT INTO lucky_four_prizes_setting_logs VALUES (8,1,1000,50,800,40,20,'0000-00-00 00:00:00')");
        $this->source->exec("INSERT INTO lucky_four_prizes VALUES (20,{$game},40,20,1000,0,50,1000)");
        $this->source->exec("INSERT INTO lucky_four_bets VALUES (90,{$bet},{$game},'0123',20,'Investment')");
        $this->source->exec("INSERT INTO lucky_four_winners VALUES (10,{$bet},'Approved','Yes',1000,'2026-10-31 20:30:00')");
        $this->source->exec("INSERT INTO admin_user_logs VALUES (30,99,'approve_winner',10,'Approve Lucky Four Winner','2026-10-31 20:30:00','Retain this original note')");
        $this->source->exec("INSERT INTO admin_user_logs VALUES (31,99,'approve_winner',10,'Approve Power Ball Winner','2026-10-31 20:30:00',NULL)");
        DB::table('lucky_four_bets')->delete();
        DB::table('lucky_four_prizes')->delete();
        DB::table('lucky_four_prizes_settings')->delete();
        DB::table('admin_lucky_four_imports')->delete();
        config(['database.connections.legacy' => ['driver' => 'sqlite', 'database' => $this->fixture, 'prefix' => '']]);
        DB::purge('legacy');
        if ($partial) {
            $this->artisan('legacy:player-reports', ['--execute' => true])->assertSuccessful();
        }
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

    public function test_dry_run_keeps_partial_bets_and_all_source_history_unchanged(): void
    {
        $this->sourceFixture();

        $this->artisan('legacy:lucky-four', ['--dry-run' => true, '--batch' => 1])->assertSuccessful();

        $this->assertDatabaseHas('lucky_four_bets', ['id' => 90, 'amount' => '20.00', 'game_id' => null, 'legacy_id' => null]);
        $this->assertDatabaseCount('lucky_four_prizes', 0);
        $this->assertDatabaseCount('admin_lucky_four_imports', 0);
        $this->assertDatabaseCount('player_wallet_transactions', 0);
        $this->assertSame(1, (int) $this->source->query('SELECT COUNT(*) FROM lucky_four_bets')->fetchColumn());
    }

    public function test_import_upgrades_report_bets_preserves_full_history_and_verifies_idempotently_without_replaying_payments(): void
    {
        $this->sourceFixture();

        $this->artisan('legacy:lucky-four', ['--execute' => true, '--batch' => 1])->assertSuccessful();
        $this->artisan('legacy:lucky-four', ['--execute' => true])->assertSuccessful();
        $this->artisan('legacy:lucky-four', ['--verify' => true])->assertSuccessful();
        $this->artisan('legacy:player-reports', ['--verify' => true])->assertSuccessful();

        $this->assertDatabaseHas('lucky_four_bets', ['id' => 90, 'legacy_id' => 90, 'combination' => '0123', 'is_added' => 'Investment', 'amount' => '20.00', 'game_id' => 1, 'ending_bet_id' => 1]);
        $this->assertDatabaseHas('lucky_four_prizes', ['id' => 20, 'game_id' => 1, 'jackpot_prize' => '1000.00']);
        $this->assertDatabaseHas('lucky_four_winners', ['id' => 10, 'ending_bet_id' => 1, 'winner_status' => 'Approved', 'prize_released' => 'Yes', 'created_at' => '2026-10-31 12:30:00']);
        $this->assertDatabaseHas('lucky_four_prizes_setting_logs', ['id' => 8, 'created_at' => null]);
        $this->assertSame(['created_at' => '0000-00-00 00:00:00'], json_decode(DB::table('lucky_four_prizes_setting_logs')->value('legacy_dates'), true));
        $this->assertDatabaseCount('admin_lucky_four_legacy_logs', 1);
        $this->assertDatabaseHas('admin_lucky_four_legacy_logs', ['id' => 30, 'game_id' => 1, 'user_id' => null]);
        $archive = json_decode(DB::table('admin_lucky_four_legacy_logs')->value('legacy_payload'), true);
        $this->assertSame('Retain this original note', $archive['remarks']);
        $this->assertDatabaseCount('lucky_four_bets', 1);
        $this->assertDatabaseCount('player_wallet_transactions', 0);
        $this->assertDatabaseHas('player_wallet', ['actual_amount' => '100.00']);
        $this->assertSame('Approved', $this->source->query('SELECT winner_status FROM lucky_four_winners')->fetchColumn());
    }

    public function test_import_without_amount_history_creates_complete_bets(): void
    {
        $this->sourceFixture(false);

        $this->artisan('legacy:lucky-four', ['--execute' => true])->assertSuccessful();

        $this->assertDatabaseHas('lucky_four_bets', ['id' => 90, 'game_id' => 1, 'combination' => '0123']);
        $this->artisan('legacy:player-reports', ['--verify' => true])->assertSuccessful();
    }

    public function test_standalone_lucky_four_tickets_migrate_without_an_ending_card(): void
    {
        $this->sourceFixture();
        DB::table('ending_bets')->where('id', 1)->update(['card_id' => 0, 'game_type_id' => 3]);

        $this->artisan('legacy:lucky-four', ['--execute' => true])->assertSuccessful();

        $this->assertDatabaseHas('lucky_four_bets', ['id' => 90, 'game_id' => 1, 'ending_bet_id' => 1, 'combination' => '0123']);
        $this->assertDatabaseHas('ending_bets', ['id' => 1, 'card_id' => 0, 'game_type_id' => 3]);
    }

    public function test_conflicting_partial_amount_stops_before_importing_any_other_history(): void
    {
        $this->sourceFixture();
        DB::table('lucky_four_bets')->where('id', 90)->update(['amount' => '21.00']);

        $this->artisan('legacy:lucky-four', ['--execute' => true])->assertFailed();

        $this->assertDatabaseHas('lucky_four_bets', ['id' => 90, 'amount' => '21.00', 'game_id' => null]);
        $this->assertDatabaseCount('lucky_four_prizes_settings', 0);
        $this->assertDatabaseCount('admin_lucky_four_imports', 0);
    }

    public function test_orphaned_winner_and_mismatched_ending_parent_reject_the_import(): void
    {
        $this->sourceFixture();
        $this->source->exec('UPDATE lucky_four_winners SET ending_bet_id = 999');
        $this->artisan('legacy:lucky-four', ['--execute' => true])->assertFailed();
        $this->source->exec('UPDATE lucky_four_winners SET ending_bet_id = 1');
        $this->source->exec('UPDATE lucky_four_bets SET game_id = 999');

        $this->artisan('legacy:lucky-four', ['--execute' => true])->assertFailed();

        $this->assertDatabaseCount('admin_lucky_four_imports', 0);
        $this->assertDatabaseCount('lucky_four_prizes_settings', 0);
        $this->assertDatabaseHas('lucky_four_bets', ['id' => 90, 'game_id' => null]);
    }

    public function test_verification_detects_source_and_target_drift(): void
    {
        $this->sourceFixture();
        $this->artisan('legacy:lucky-four', ['--execute' => true])->assertSuccessful();
        $this->source->exec("UPDATE lucky_four_bets SET combination = '9999'");
        $this->artisan('legacy:lucky-four', ['--verify' => true])->assertFailed();
        $this->source->exec("UPDATE lucky_four_bets SET combination = '0123'");
        DB::table('lucky_four_bets')->where('id', 90)->update(['combination' => '8888']);

        $this->artisan('legacy:lucky-four', ['--verify' => true])->assertFailed();

        $this->assertDatabaseHas('lucky_four_bets', ['id' => 90, 'combination' => '8888']);
        $this->assertDatabaseCount('player_wallet_transactions', 0);
    }

    public function test_settings_changed_in_new_admin_keep_import_provenance_and_are_not_overwritten(): void
    {
        $this->sourceFixture();
        $this->artisan('legacy:lucky-four', ['--execute' => true])->assertSuccessful();
        $this->luckyAdmin();
        $this->put(route('lucky-four.settings.update'), ['jackpot_prize' => '2000', 'three_digits_prize' => '100', 'initial_amount' => '2000', 'bet_amount' => '30', 'percentage_deduction' => '20'])->assertSessionHasNoErrors();

        $this->artisan('legacy:lucky-four', ['--execute' => true])->assertSuccessful();

        $this->assertDatabaseHas('lucky_four_prizes_settings', ['id' => 1, 'jackpot_prize' => '2000.00']);
        $this->assertDatabaseCount('lucky_four_prizes_setting_logs', 2);
    }

    public function test_reverification_retains_a_missing_historical_timestamp_and_import_provenance(): void
    {
        $this->sourceFixture();
        $this->source->exec("UPDATE lucky_four_winners SET winner_status = 'Verified', prize_released = 'No', created_at = NULL");
        $this->artisan('legacy:lucky-four', ['--execute' => true])->assertSuccessful();
        $this->luckyAdmin();
        $this->post(route('lucky-four.games.winner', [1, 'unverify']), ['ending_bet_id' => 1])->assertSessionHasNoErrors();

        $this->post(route('lucky-four.games.winner', [1, 'verify']), ['ending_bet_id' => 1])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('lucky_four_winners', ['id' => 10, 'winner_status' => 'Verified', 'created_at' => null]);
        $this->artisan('legacy:lucky-four', ['--verify' => true])->assertSuccessful();
        $this->assertDatabaseCount('player_wallet_transactions', 0);
    }
}
