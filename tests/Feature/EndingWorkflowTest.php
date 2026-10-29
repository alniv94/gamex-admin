<?php

namespace Tests\Feature;

use App\Models\AdminEndingLog;
use App\Models\EndingGame;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Tests\Support\EndingFixtures;
use Tests\TestCase;

class EndingWorkflowTest extends TestCase
{
    use EndingFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 10, 29)->setTime(8, 0));
        $this->endingFixtures();
    }

    private function createGame(): int
    {
        $response = $this->post('/ending/games', ['team_a' => 1, 'team_b' => 2, 'schedule' => '2026-10-30T18:00'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $game = EndingGame::query()->latest('id')->firstOrFail();
        $response->assertRedirect(route('ending.games.show', $game));

        return $game->id;
    }

    private function startedGame(): int
    {
        $id = $this->createGame();
        $this->travelTo(now()->addDays(2));

        return $id;
    }

    private function postResult(int $game, int $quarter = 1, int $a = 21, int $b = 30): TestResponse
    {
        return $this->post("/ending/games/$game/results", ['quarter_id' => $quarter, 'team_a_score' => $a, 'team_b_score' => $b]);
    }

    private function bet(int $game, int $quarter = 0, int $slot = 11): int
    {
        $amount = DB::table('ending_bet_amounts')->where('game_id', $game)->value('id');
        $card = DB::table('ending_cards')->where('bet_amount_id', $amount)->value('id');

        return DB::table('ending_bets')->insertGetId(['player_id' => 9, 'card_id' => $card, 'slot_id' => $slot, 'quarter_id' => $quarter]);
    }

    public function test_creation_snapshots_presets_prizes_time_and_audit(): void
    {
        $user = $this->endingAdmin();
        $game = $this->createGame();
        $this->assertDatabaseHas('ending_games', ['id' => $game, 'schedule' => '2026-10-30 10:00:00', 'betting' => 'Disabled']);
        $this->assertDatabaseCount('ending_cards', 2);
        $this->assertDatabaseHas('ending_bet_amount_prizes', ['quarter_id' => 1, 'cash_prize' => '150.00']);
        $this->assertDatabaseHas('ending_bet_amount_prizes', ['quarter_id' => 4, 'cash_prize' => '600.00']);
        $this->assertDatabaseHas('admin_ending_logs', ['game_id' => $game, 'user_id' => $user->id, 'action' => 'create_game']);
        $this->get("/ending/games/$game")->assertOk();
        $this->get('/ending/games?date_from=2026-10-30&date_to=2026-10-30')->assertOk();
        $this->get('/ending/history')->assertOk();
    }

    public function test_guest_and_permission_checks_apply_to_pages_and_every_action(): void
    {
        $this->get('/ending/games')->assertRedirect('/login');
        $this->post('/ending/games')->assertRedirect('/login');
        $this->endingAdmin([17]);
        $this->get('/ending/games')->assertOk();
        $game = EndingGame::factory()->create(['team_a' => 1, 'team_b' => 2]);
        $amount = $game->betAmounts()->create(['amount' => '15.00', 'bet_type_id' => 2]);
        $card = $amount->cards()->create([]);
        $result = $game->results()->create(['quarter_id' => 1, 'team_a_score' => 0, 'team_b_score' => 0, 'slot_id' => 1]);
        foreach ([
            ['post', ''], ['put', "/$game->id"], ['put', "/$game->id/betting"],
            ['post', "/$game->id/cards"], ['put', "/$game->id/cards/$card->id/betting"],
            ['post', "/$game->id/results"], ['put', "/$game->id/results/$result->id"],
            ['post', "/$game->id/winners/verify"], ['post', "/$game->id/winners/unverify"], ['post', "/$game->id/winners/approve"],
        ] as [$method, $path]) {
            $this->{$method}('/ending/games'.$path)->assertForbidden();
        }
        $this->assertDatabaseCount('ending_games', 1);
    }

    public function test_migration_gate_and_concurrent_writer_refuse_mutations(): void
    {
        $this->endingAdmin();
        $lock = Cache::lock('ending:write', 30);
        $lock->get();
        $this->post('/ending/games', ['team_a' => 1, 'team_b' => 2, 'schedule' => '2026-10-30T18:00'])->assertSessionHasErrors('game');
        $lock->release();
        DB::table('admin_ending_imports')->delete();
        $this->post('/ending/games', ['team_a' => 1, 'team_b' => 2, 'schedule' => '2026-10-30T18:00'])->assertSessionHasErrors('game');
    }

    public function test_invalid_teams_and_missing_configuration_roll_back_the_entire_game(): void
    {
        $this->endingAdmin();
        $this->post('/ending/games', ['team_a' => 1, 'team_b' => 3, 'schedule' => '2026-10-30T18:00'])->assertSessionHasErrors();
        DB::table('ending_config')->delete();
        $this->post('/ending/games', ['team_a' => 1, 'team_b' => 2, 'schedule' => '2026-10-30T18:00'])->assertSessionHasErrors();
        $this->assertDatabaseCount('ending_games', 0);
        $this->assertDatabaseCount('ending_cards', 0);
        $this->assertDatabaseCount('admin_ending_logs', 0);
    }

    public function test_betting_cutoff_closes_once_and_cannot_reopen(): void
    {
        $this->endingAdmin();
        $game = $this->createGame();
        $this->put("/ending/games/$game/betting", ['enabled' => true])->assertRedirect()->assertSessionHasNoErrors();
        $this->travelTo(now()->setDate(2026, 10, 30)->setTime(9, 31));
        $this->artisan('ending:close-betting')->assertSuccessful();
        $this->artisan('ending:close-betting')->assertSuccessful();
        $this->assertDatabaseHas('ending_games', ['id' => $game, 'betting' => 'Disabled']);
        $this->assertSame(1, DB::table('admin_ending_logs')->where('action', 'automatic_betting_close')->count());
        $this->put("/ending/games/$game/betting", ['enabled' => true])->assertSessionHasErrors();
        $this->travelTo(now()->setTime(10, 0));
        $this->artisan('ending:close-betting')->assertSuccessful();
        $this->assertDatabaseHas('ending_games', ['id' => $game, 'game_status_id' => 1]);
        $this->assertSame(1, DB::table('admin_ending_logs')->where('action', 'game_started')->count());
    }

    public function test_quarter_order_duplicates_and_score_validation(): void
    {
        $this->endingAdmin();
        $game = $this->startedGame();
        $this->postResult($game, 2)->assertSessionHasErrors();
        $this->postResult($game)->assertRedirect()->assertSessionHasNoErrors();
        $this->postResult($game)->assertSessionHasErrors();
        $this->postResult($game, 2, -1)->assertSessionHasErrors();
        $this->postResult($game, 2)->assertRedirect()->assertSessionHasNoErrors();
        $this->postResult($game, 3)->assertRedirect()->assertSessionHasNoErrors();
        $this->postResult($game, 4, 101, 90)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('ending_games', ['id' => $game, 'game_status_id' => 5]);
        $this->assertDatabaseHas('ending_game_results', ['game_id' => $game, 'quarter_id' => 4, 'slot_id' => 11]);
        $this->assertDatabaseCount('ending_game_results', 4);
    }

    public function test_winner_verification_declaration_is_idempotent_and_locks_score(): void
    {
        $this->endingAdmin();
        $game = $this->startedGame();
        $bet = $this->bet($game);
        $loser = $this->bet($game, 0, 12);
        $this->postResult($game)->assertRedirect()->assertSessionHasNoErrors();
        $payload = ['bet_id' => $bet, 'quarter_id' => 1];
        $url = "/ending/games/$game/winners";
        $this->post("$url/approve", $payload)->assertSessionHasErrors();
        $this->post("$url/verify", ['bet_id' => $loser, 'quarter_id' => 1])->assertSessionHasErrors();
        $this->post("$url/verify", $payload)->assertRedirect()->assertSessionHasNoErrors();
        $this->post("$url/verify", $payload)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseCount('ending_game_winners', 1);
        $result = DB::table('ending_game_results')->value('id');
        $correction = ['quarter_id' => 1, 'team_a_score' => 22, 'team_b_score' => 30, 'reason' => 'Official correction'];
        $this->put("/ending/games/$game/results/$result", $correction)->assertSessionHasErrors();
        $this->post("$url/approve", $payload)->assertRedirect()->assertSessionHasNoErrors();
        $this->post("$url/approve", $payload)->assertRedirect()->assertSessionHasNoErrors();
        $this->post("$url/unverify", $payload)->assertSessionHasErrors();
        $this->assertDatabaseHas('ending_game_winners', ['bet_id' => $bet, 'winner_status_id' => 2, 'prize_released' => 'No']);
        $this->assertDatabaseHas('ending_bets', ['id' => $bet, 'result' => 'Win']);
        $this->assertSame(1, DB::table('admin_ending_logs')->where('action', 'declare_winner')->count());
        $declaration = AdminEndingLog::query()->where('action', 'declare_winner')->sole();
        $this->assertSame(1, $declaration->before['winner_status_id']);
        $this->assertSame('Approved', $declaration->after['status']);
    }

    public function test_unverify_allows_reasoned_correction_and_recalculates_candidates(): void
    {
        $this->endingAdmin();
        $game = $this->startedGame();
        $bet = $this->bet($game);
        $this->postResult($game)->assertRedirect()->assertSessionHasNoErrors();
        $payload = ['bet_id' => $bet, 'quarter_id' => 1];
        $this->post("/ending/games/$game/winners/verify", $payload)->assertRedirect()->assertSessionHasNoErrors();
        $this->post("/ending/games/$game/winners/unverify", $payload)->assertRedirect()->assertSessionHasNoErrors();
        $result = DB::table('ending_game_results')->value('id');
        $this->put("/ending/games/$game/results/$result", ['quarter_id' => 1, 'team_a_score' => 22, 'team_b_score' => 30])->assertSessionHasErrors();
        $this->put("/ending/games/$game/results/$result", ['quarter_id' => 1, 'team_a_score' => 22, 'team_b_score' => 30, 'reason' => 'Official correction'])->assertRedirect()->assertSessionHasNoErrors();
        $this->post("/ending/games/$game/winners/verify", $payload)->assertSessionHasErrors();
        $this->assertDatabaseHas('admin_ending_logs', ['action' => 'update_result', 'reason' => 'Official correction']);
        $correction = AdminEndingLog::query()->where('action', 'update_result')->sole();
        $this->assertSame(21, $correction->before['team_a_score']);
        $this->assertSame(22, $correction->after['team_a_score']);
    }

    public function test_cross_game_and_quarantined_winner_requests_are_rejected(): void
    {
        $this->endingAdmin();
        $game = $this->createGame();
        $other = $this->createGame();
        $this->travelTo(now()->addDays(2));
        $bet = $this->bet($game);
        $this->postResult($game)->assertRedirect()->assertSessionHasNoErrors();
        $this->postResult($other)->assertRedirect()->assertSessionHasNoErrors();
        $this->post("/ending/games/$other/winners/verify", ['bet_id' => $bet, 'quarter_id' => 1])->assertSessionHasErrors();
        DB::table('ending_bets')->where('id', $bet)->update(['player_id' => null, 'legacy_player_id' => 74, 'legacy_player_mapping_id' => 101]);
        $this->post("/ending/games/$game/winners/verify", ['bet_id' => $bet, 'quarter_id' => 1])->assertSessionHasErrors();
        $this->assertDatabaseCount('ending_game_winners', 0);
    }

    public function test_cards_keep_non_cash_prizes_and_cannot_disable_sold_cards(): void
    {
        $this->endingAdmin();
        $game = $this->createGame();
        $this->post("/ending/games/$game/cards", ['bet_type_id' => 1, 'amount' => '50.00', 'quantity' => 1, 'prizes' => array_map(fn ($q) => ['quarter_id' => $q, 'is_cash' => 'Not Cash', 'other_prize' => 'Gift voucher'], [1, 2, 3, 4])])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('ending_bet_amount_prizes', ['is_cash' => 'Not Cash', 'other_prize' => 'Gift voucher', 'cash_prize' => null]);
        $this->bet($game);
        $card = DB::table('ending_cards')->value('id');
        $this->put("/ending/games/$game/cards/$card/betting", ['enabled' => false])->assertSessionHasErrors();
        $this->get("/ending/games/$game?card_id=$card")->assertInertia(fn (AssertableInertia $page) => $page
            ->reloadOnly('cardBets', fn (AssertableInertia $partial) => $partial->has('cardBets', 1)));
    }

    public function test_matching_retains_the_legacy_rules_for_both_bet_types(): void
    {
        $this->endingAdmin();
        $game = $this->createGame();
        $this->post("/ending/games/$game/cards", ['bet_type_id' => 1, 'amount' => '50.00', 'quantity' => 1,
            'prizes' => array_map(fn ($q) => ['quarter_id' => $q, 'is_cash' => 'Cash', 'cash_prize' => '3500.00'], [1, 2, 3, 4]),
        ])->assertRedirect()->assertSessionHasNoErrors();
        $amount = DB::table('ending_bet_amounts')->where('game_id', $game)->where('bet_type_id', 1)->value('id');
        $card = DB::table('ending_cards')->where('bet_amount_id', $amount)->value('id');
        $q1 = DB::table('ending_bets')->insertGetId(['player_id' => 9, 'card_id' => $card, 'slot_id' => 11, 'quarter_id' => 1]);
        $q2 = DB::table('ending_bets')->insertGetId(['player_id' => 9, 'card_id' => $card, 'slot_id' => 11, 'quarter_id' => 2]);
        $all = $this->bet($game);
        $this->travelTo(now()->addDays(2));
        $this->postResult($game)->assertRedirect()->assertSessionHasNoErrors();
        $this->postResult($game, 2)->assertRedirect()->assertSessionHasNoErrors();
        $url = "/ending/games/$game/winners/verify";
        $this->post($url, ['bet_id' => $q1, 'quarter_id' => 1])->assertRedirect()->assertSessionHasNoErrors();
        $this->post($url, ['bet_id' => $q1, 'quarter_id' => 2])->assertSessionHasErrors();
        $this->post($url, ['bet_id' => $q2, 'quarter_id' => 1])->assertSessionHasErrors();
        $this->post($url, ['bet_id' => $q2, 'quarter_id' => 2])->assertRedirect()->assertSessionHasNoErrors();
        $this->post($url, ['bet_id' => $all, 'quarter_id' => 1])->assertRedirect()->assertSessionHasNoErrors();
        $this->post($url, ['bet_id' => $all, 'quarter_id' => 2])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseCount('ending_game_winners', 4);
    }
}
