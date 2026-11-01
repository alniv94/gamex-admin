<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Support\SessionKey;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Support\OtsoPanaloFixtures;
use Tests\TestCase;

class OtsoPanaloWorkflowTest extends TestCase
{
    use OtsoPanaloFixtures, RefreshDatabase;

    public function test_new_game_uses_active_jackpot_without_carrying_previous_pool_or_prize(): void
    {
        $this->otsoFixtures();
        $this->otsoAdmin();
        $previous = $this->otsoGame(['jackpot_prize' => '9999.00']);
        $this->otsoBet($previous, 0);
        $this->otsoApprovedResult($previous);
        $ending = DB::table('ending_games')->insertGetId(['team_a' => 1, 'team_b' => 2, 'schedule' => '2026-11-03 10:00:00']);

        $response = $this->post(route('otso.games.store'), ['ending_game_id' => $ending, 'jackpot_prize' => '500000', 'rollover_amount' => '100000']);

        $id = DB::table('otso_panalo_games')->where('ending_game_id', $ending)->value('id');
        $response->assertRedirect(route('otso.games.show', $id))->assertSessionHasNoErrors();
        $response->assertSessionHas(SessionKey::FLASH_DATA.'.success', 'Otso Panalo game created.');
        $this->assertDatabaseHas('otso_panalo_games', ['id' => $id, 'jackpot_prize' => '1000.00', 'game_status_id' => 1, 'betting' => 'Disabled', 'betting_deadline' => '2026-11-03 09:30:00']);
        $this->assertDatabaseCount('admin_otso_panalo_audits', 1);
    }

    #[TestWith([1])]
    #[TestWith([4])]
    public function test_new_game_ignores_unresolved_and_cancelled_previous_jackpots(int $status): void
    {
        $this->otsoFixtures();
        $this->otsoAdmin();
        $this->otsoGame(['jackpot_prize' => '9999.00', 'game_status_id' => $status]);
        $ending = DB::table('ending_games')->insertGetId(['team_a' => 1, 'team_b' => 2, 'schedule' => '2026-11-03 10:00:00']);

        $this->post(route('otso.games.store'), ['ending_game_id' => $ending])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('otso_panalo_games', ['ending_game_id' => $ending, 'jackpot_prize' => '1000.00']);
    }

    public function test_secondary_approval_creates_a_missing_wallet_before_crediting(): void
    {
        $this->otsoFixtures();
        $this->otsoAdmin();
        DB::table('player_wallet')->delete();
        $game = $this->otsoGame();
        $this->otsoBet($game, 3);
        $this->otsoApprovedResult($game);
        $this->post(route('otso.winners.generate', $game))->assertSessionHasNoErrors();

        $this->post(route('otso.winners.approve', $game))->assertSessionHasNoErrors();

        $this->assertDatabaseHas('player_wallet', ['player_id' => 9, 'actual_amount' => '100.00']);
        $this->assertDatabaseCount('player_wallet_transactions', 1);
    }

    public function test_configuration_activation_is_exclusive_and_existing_game_prizes_are_preserved(): void
    {
        $this->otsoFixtures();
        $this->otsoAdmin();
        $game = $this->otsoGame();

        $this->post(route('otso.configs.store'), ['name' => 'New Config', 'status' => 'Active', 'bet_amount' => '30', 'jackpot_prize' => '2000',
            'second_prize' => '120', 'third_prize' => '60', 'fourth_prize' => '15', 'pool_percentage' => '25'])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('otso_panalo_game_configs', ['id' => 1, 'status' => 'Inactive']);
        $this->assertDatabaseHas('otso_panalo_game_configs', ['name' => 'New Config', 'status' => 'Active', 'jackpot_prize' => '2000.00']);
        $this->assertDatabaseHas('otso_panalo_games', ['id' => $game, 'jackpot_prize' => '1000.00']);
    }

    public function test_duplicate_game_and_unverified_migration_are_rejected(): void
    {
        $this->otsoFixtures();
        $this->otsoAdmin();
        $game = $this->otsoGame();
        $ending = DB::table('otso_panalo_games')->where('id', $game)->value('ending_game_id');

        $this->post(route('otso.games.store'), ['ending_game_id' => $ending])->assertSessionHasErrors('action');
        DB::table('admin_otso_panalo_imports')->delete();
        $this->put(route('otso.games.betting', $game), ['enabled' => true])->assertSessionHasErrors('action');

        $this->assertDatabaseCount('otso_panalo_games', 1);
        $this->assertDatabaseCount('admin_otso_panalo_audits', 0);
    }

    public function test_betting_enforces_cutoff_and_started_game_cannot_be_edited(): void
    {
        $this->otsoFixtures();
        $this->otsoAdmin();
        $game = $this->otsoGame(['betting_deadline' => '2026-10-31 12:00:00']);
        $ending = DB::table('otso_panalo_games')->where('id', $game)->value('ending_game_id');

        $this->put(route('otso.games.betting', $game), ['enabled' => true])->assertSessionHasErrors('action');
        DB::table('otso_panalo_games')->where('id', $game)->update(['game_status_id' => 2]);
        $this->put(route('otso.games.update', $game), ['ending_game_id' => $ending, 'betting_deadline' => '2026-11-02T17:30'])->assertSessionHasErrors('action');

        $this->assertDatabaseHas('otso_panalo_games', ['id' => $game, 'betting' => 'Disabled', 'game_status_id' => 2]);
    }

    public function test_result_creation_closes_betting_then_requires_current_version_for_approval(): void
    {
        $this->otsoFixtures();
        $this->otsoAdmin();
        $game = $this->otsoGame(['betting' => 'Enabled']);

        $this->post(route('otso.results.store', $game), ['first_quarter_result' => '1-2'])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('otso_panalo_games', ['id' => $game, 'game_status_id' => 3, 'betting' => 'Disabled']);
        $this->post(route('otso.winners.generate', $game))->assertSessionHasErrors('action');
        $this->put(route('otso.results.update', $game), ['first_quarter_result' => '2-3', 'version' => 1])->assertSessionHasNoErrors();
        $this->post(route('otso.results.approve', $game), ['version' => 1])->assertSessionHasErrors('action');
        $this->post(route('otso.results.approve', $game), ['version' => 2])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('otso_panalo_game_results', ['game_id' => $game, 'status' => 'Approved', 'version' => 2, 'first_quarter_result' => '2-3']);
        $this->put(route('otso.results.update', $game), ['first_quarter_result' => '3-4', 'version' => 2])->assertSessionHasErrors('action');
        $this->post(route('otso.results.store', $game), ['first_quarter_result' => '3-4'])->assertSessionHasErrors('action');
        $this->assertDatabaseCount('otso_panalo_game_results', 1);
    }

    public function test_result_validation_rejects_empty_and_malformed_combinations(): void
    {
        $this->otsoFixtures();
        $this->otsoAdmin();
        $game = $this->otsoGame();

        $this->post(route('otso.results.store', $game), [])->assertSessionHasErrors(['first_quarter_result' => 'At least one quarter result is required.']);
        $this->post(route('otso.results.store', $game), ['first_quarter_result' => '12-3'])->assertSessionHasErrors('first_quarter_result');

        $this->assertDatabaseCount('otso_panalo_game_results', 0);
    }

    public function test_winner_computation_splits_jackpot_and_assigns_each_secondary_tier_once(): void
    {
        $this->otsoFixtures();
        $this->otsoAdmin();
        $game = $this->otsoGame();
        foreach ([4, 4, 3, 2, 1, 0] as $matches) {
            $this->otsoBet($game, $matches);
        }
        $this->otsoBet($game, 4, ['refunded' => 'Yes']);
        $this->otsoApprovedResult($game);

        $this->post(route('otso.winners.generate', $game))->assertSessionHasNoErrors();
        $this->post(route('otso.winners.generate', $game))->assertSessionHasNoErrors();

        $this->assertDatabaseCount('otso_panalo_winners', 5);
        $this->assertDatabaseCount('otso_panalo_bet_quarter_results', 24);
        $this->assertSame(2, DB::table('otso_panalo_winners')->where('winner_type_id', 1)->where('prize_amount', '500.00')->count());
        $this->assertDatabaseHas('otso_panalo_winners', ['winner_type_id' => 2, 'prize_amount' => '100.00']);
        $this->assertDatabaseHas('otso_panalo_winners', ['winner_type_id' => 3, 'prize_amount' => '50.00']);
        $this->assertDatabaseHas('otso_panalo_winners', ['winner_type_id' => 4, 'prize_amount' => '10.00']);
        $this->assertDatabaseCount('player_wallet_transactions', 0);
    }

    public function test_zero_bet_generation_is_recorded_and_does_not_repeat(): void
    {
        $this->otsoFixtures();
        $this->otsoAdmin();
        $game = $this->otsoGame();
        $this->otsoApprovedResult($game);

        $this->post(route('otso.winners.generate', $game))->assertSessionHasNoErrors();
        $this->post(route('otso.winners.generate', $game))->assertSessionHasNoErrors();

        $this->assertNotNull(DB::table('otso_panalo_games')->where('id', $game)->value('winners_generated_at'));
        $this->assertDatabaseCount('admin_otso_panalo_audits', 1);
        $this->assertDatabaseCount('otso_panalo_winners', 0);
    }

    public function test_approval_credits_secondary_prizes_once_and_keeps_jackpot_unreleased(): void
    {
        $this->otsoFixtures();
        $this->otsoAdmin();
        $game = $this->otsoGame();
        $jackpotBet = $this->otsoBet($game, 4);
        $this->otsoBet($game, 3);
        $this->otsoApprovedResult($game);
        $this->post(route('otso.winners.generate', $game))->assertSessionHasNoErrors();

        $this->post(route('otso.winners.approve', $game))->assertSessionHasNoErrors();
        $this->post(route('otso.winners.approve', $game))->assertSessionHasNoErrors();

        $this->assertDatabaseHas('player_wallet', ['id' => 10, 'actual_amount' => '200.00']);
        $this->assertDatabaseCount('player_wallet_transactions', 1);
        $this->assertDatabaseCount('admin_otso_panalo_settlements', 1);
        $this->assertDatabaseHas('otso_panalo_winners', ['bet_id' => $jackpotBet, 'winner_status' => 'Approved', 'prize_released' => 'No']);
        $this->assertDatabaseHas('otso_panalo_winners', ['winner_type_id' => 2, 'winner_status' => 'Approved', 'prize_released' => 'Yes']);
    }

    public function test_unlinked_secondary_winner_rolls_back_entire_approval_batch(): void
    {
        $this->otsoFixtures();
        $this->otsoAdmin();
        $game = $this->otsoGame();
        $this->otsoBet($game, 3);
        $this->otsoBet($game, 2, ['player_id' => null, 'legacy_player_id' => 74]);
        $this->otsoApprovedResult($game);
        $this->post(route('otso.winners.generate', $game))->assertSessionHasNoErrors();

        $this->post(route('otso.winners.approve', $game))->assertSessionHasErrors('action');

        $this->assertDatabaseHas('player_wallet', ['id' => 10, 'actual_amount' => '100.00']);
        $this->assertDatabaseCount('player_wallet_transactions', 0);
        $this->assertDatabaseMissing('otso_panalo_winners', ['winner_status' => 'Approved']);
    }

    public function test_cancellation_refunds_wagers_once_and_blocks_result_creation(): void
    {
        $this->otsoFixtures();
        $this->otsoAdmin();
        $game = $this->otsoGame();
        $this->otsoBet($game);
        $this->otsoBet($game, 1, ['refunded' => 'Yes']);

        $this->post(route('otso.games.cancel', $game), ['reason' => 'postponed_beyond_48_hours'])->assertSessionHasNoErrors();
        $this->post(route('otso.games.cancel', $game), ['reason' => 'postponed_beyond_48_hours'])->assertSessionHasNoErrors();
        $this->post(route('otso.results.store', $game), ['first_quarter_result' => '1-2'])->assertSessionHasErrors('action');

        $this->assertDatabaseHas('player_wallet', ['id' => 10, 'actual_amount' => '120.00']);
        $this->assertDatabaseCount('player_wallet_transactions', 1);
        $this->assertDatabaseHas('otso_panalo_games', ['id' => $game, 'game_status_id' => 4, 'cancellation_reason' => 'postponed_beyond_48_hours']);
        $this->assertDatabaseMissing('otso_panalo_bets', ['game_id' => $game, 'refunded' => 'No']);
    }

    public function test_unlinked_refund_rolls_back_all_wallet_and_game_changes(): void
    {
        $this->otsoFixtures();
        $this->otsoAdmin();
        $game = $this->otsoGame();
        $this->otsoBet($game);
        $this->otsoBet($game, 1, ['player_id' => null]);

        $this->post(route('otso.games.cancel', $game), ['reason' => 'cancelled'])->assertSessionHasErrors('action');

        $this->assertDatabaseHas('player_wallet', ['id' => 10, 'actual_amount' => '100.00']);
        $this->assertDatabaseHas('otso_panalo_games', ['id' => $game, 'game_status_id' => 1]);
        $this->assertDatabaseCount('player_wallet_transactions', 0);
        $this->assertDatabaseMissing('otso_panalo_bets', ['refunded' => 'Yes']);
    }
}
