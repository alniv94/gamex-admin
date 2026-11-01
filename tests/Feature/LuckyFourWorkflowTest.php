<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Support\LuckyFourFixtures;
use Tests\TestCase;

class LuckyFourWorkflowTest extends TestCase
{
    use LuckyFourFixtures, RefreshDatabase;

    public function test_switch_snapshots_settings_and_does_not_remove_prize_history(): void
    {
        $this->luckyFixtures();
        $this->luckyAdmin();
        $game = $this->luckyGame(false);
        DB::table('ending_games')->where('id', $game)->update(['is_lucky_four' => 'No']);
        DB::table('lucky_four_prizes_settings')->where('id', 1)->update(['jackpot_prize' => '2500.00']);

        $this->put(route('lucky-four.games.toggle', $game), ['enabled' => true])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('ending_games', ['id' => $game, 'is_lucky_four' => 'Yes']);
        $this->assertDatabaseHas('lucky_four_prizes', ['game_id' => $game, 'initial_amount' => '2500.00', 'jackpot_prize' => '2500.00']);
        $this->put(route('lucky-four.games.toggle', $game), ['enabled' => true])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('admin_lucky_four_audits', 1);
        $this->put(route('lucky-four.games.toggle', $game), ['enabled' => false])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('ending_games', ['id' => $game, 'is_lucky_four' => 'No']);
        $this->assertDatabaseCount('lucky_four_prizes', 1);
    }

    #[TestWith(['bets'])]
    #[TestWith(['started'])]
    #[TestWith(['unverified-import'])]
    public function test_switch_refuses_changes_after_bets_start_or_without_verified_history(string $condition): void
    {
        $this->luckyFixtures();
        $this->luckyAdmin();
        $game = $this->luckyGame($condition === 'started');
        if ($condition === 'bets') {
            $this->luckyBet($game);
        } elseif ($condition === 'unverified-import') {
            DB::table('admin_lucky_four_imports')->delete();
        }

        $this->put(route('lucky-four.games.toggle', $game), ['enabled' => false])->assertSessionHasErrors('action');

        $this->assertDatabaseHas('ending_games', ['id' => $game, 'is_lucky_four' => 'Yes']);
        $this->assertDatabaseCount('admin_lucky_four_audits', 0);
    }

    public function test_lucky_four_can_be_enabled_and_disabled_while_ending_betting_is_open_before_any_lucky_four_bets(): void
    {
        $this->luckyFixtures();
        $this->luckyAdmin();
        $game = $this->luckyGame(false);
        DB::table('ending_games')->where('id', $game)->update(['is_lucky_four' => 'No', 'betting' => 'Enabled']);
        DB::table('lucky_four_prizes')->where('game_id', $game)->delete();

        $this->put(route('lucky-four.games.toggle', $game), ['enabled' => true])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('ending_games', ['id' => $game, 'is_lucky_four' => 'Yes', 'betting' => 'Enabled']);
        $this->assertDatabaseHas('lucky_four_prizes', ['game_id' => $game, 'jackpot_prize' => '1000.00', 'bet_amount' => '20.00']);
        $this->put(route('lucky-four.games.toggle', $game), ['enabled' => false])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('ending_games', ['id' => $game, 'is_lucky_four' => 'No', 'betting' => 'Enabled']);
        $this->assertDatabaseCount('admin_lucky_four_audits', 2);
    }

    public function test_refresh_adds_only_pending_wagers_once_and_locks_after_verification(): void
    {
        $this->luckyFixtures();
        $this->luckyAdmin();
        $game = $this->luckyGame();
        $bet = $this->luckyBet($game, pool: 'No');
        $this->luckyBet($game, '9999', 'Yes');
        $this->luckyBet($game, '8888');

        $this->post(route('lucky-four.games.refresh', $game))->assertSessionHasNoErrors();
        $this->post(route('lucky-four.games.refresh', $game))->assertSessionHasNoErrors();

        $this->assertDatabaseHas('lucky_four_prizes', ['game_id' => $game, 'jackpot_prize' => '1008.00', 'amount_added' => '8.00']);
        $this->assertDatabaseHas('lucky_four_bets', ['ending_bet_id' => $bet, 'is_added' => 'Yes']);
        $this->assertDatabaseCount('admin_lucky_four_audits', 1);
        $this->post(route('lucky-four.games.winner', [$game, 'verify']), ['ending_bet_id' => $bet])->assertSessionHasNoErrors();
        $this->post(route('lucky-four.games.refresh', $game))->assertSessionHasErrors('action');
        $this->assertDatabaseHas('lucky_four_prizes', ['game_id' => $game, 'jackpot_prize' => '1008.00']);
    }

    public function test_winners_preserve_leading_zero_split_jackpot_and_exclude_jackpot_from_three_digit_prizes(): void
    {
        $this->luckyFixtures();
        $this->luckyAdmin();
        $game = $this->luckyGame();
        $jackpot = $this->luckyBet($game);
        $this->luckyBet($game);
        $this->luckyBet($game);
        $first = $this->luckyBet($game, '0129');
        $last = $this->luckyBet($game, '9123');
        $this->luckyBet($game, '9999');

        $this->get(route('lucky-four.games.show', $game))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->component('lucky-four/Game')->where('combination', '0123')->has('winners.data', 5)
            ->where('winners.data.0.tier', 'Jackpot')->where('winners.data.3.tier', 'Three digits'));
        foreach ([$jackpot, $first, $last] as $bet) {
            $this->post(route('lucky-four.games.winner', [$game, 'verify']), ['ending_bet_id' => $bet])->assertSessionHasNoErrors();
        }
        $this->assertDatabaseHas('lucky_four_winners', ['ending_bet_id' => $jackpot, 'prize_amount' => '333.33']);
        $this->assertDatabaseHas('lucky_four_winners', ['ending_bet_id' => $first, 'prize_amount' => '50.00']);
        $this->assertDatabaseHas('lucky_four_winners', ['ending_bet_id' => $last, 'prize_amount' => '50.00']);
    }

    public function test_approval_credits_wallet_and_ledger_exactly_once_and_blocks_unverification(): void
    {
        $this->luckyFixtures();
        $this->luckyAdmin();
        $game = $this->luckyGame();
        $bet = $this->luckyBet($game);
        $this->post(route('lucky-four.games.winner', [$game, 'verify']), ['ending_bet_id' => $bet])->assertSessionHasNoErrors();

        $this->post(route('lucky-four.games.winner', [$game, 'approve']), ['ending_bet_id' => $bet])->assertSessionHasNoErrors();
        $this->post(route('lucky-four.games.winner', [$game, 'approve']), ['ending_bet_id' => $bet])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('player_wallet', ['player_id' => 9, 'actual_amount' => '1100.00']);
        $this->assertDatabaseCount('player_wallet_transactions', 1);
        $this->assertDatabaseCount('admin_lucky_four_settlements', 1);
        $this->assertDatabaseHas('lucky_four_winners', ['ending_bet_id' => $bet, 'winner_status' => 'Approved', 'prize_released' => 'Yes']);
        $this->assertDatabaseHas('ending_bets', ['id' => $bet, 'result' => 'Win']);
        $this->post(route('lucky-four.games.winner', [$game, 'unverify']), ['ending_bet_id' => $bet])->assertSessionHasErrors('action');
        $this->assertDatabaseCount('player_wallet_transactions', 1);
    }

    public function test_wallet_overflow_rolls_back_all_approval_changes(): void
    {
        $this->luckyFixtures();
        $this->luckyAdmin();
        $game = $this->luckyGame();
        $bet = $this->luckyBet($game);
        $this->post(route('lucky-four.games.winner', [$game, 'verify']), ['ending_bet_id' => $bet])->assertSessionHasNoErrors();
        DB::table('player_wallet')->update(['actual_amount' => '99999999.99']);

        $this->post(route('lucky-four.games.winner', [$game, 'approve']), ['ending_bet_id' => $bet])->assertSessionHasErrors('action');

        $this->assertDatabaseHas('lucky_four_winners', ['ending_bet_id' => $bet, 'winner_status' => 'Verified', 'prize_released' => 'No']);
        $this->assertDatabaseHas('ending_bets', ['id' => $bet, 'result' => 'Lose']);
        $this->assertDatabaseCount('player_wallet_transactions', 0);
        $this->assertDatabaseCount('admin_lucky_four_settlements', 0);
    }

    #[TestWith(['missing-result'])]
    #[TestWith(['duplicate-result'])]
    #[TestWith(['unlinked-player'])]
    #[TestWith(['losing-bet'])]
    #[TestWith(['pending-pool'])]
    public function test_ineligible_or_incomplete_tickets_cannot_be_verified(string $condition): void
    {
        $this->luckyFixtures();
        $this->luckyAdmin();
        $game = $this->luckyGame();
        $bet = $this->luckyBet($game, $condition === 'losing-bet' ? '9999' : '0123', $condition === 'pending-pool' ? 'No' : 'Investment', $condition === 'unlinked-player' ? null : 9);
        if ($condition === 'missing-result') {
            DB::table('ending_game_results')->where('quarter_id', 4)->delete();
        } elseif ($condition === 'duplicate-result') {
            DB::table('ending_game_results')->where('quarter_id', 4)->update(['quarter_id' => 3]);
        }

        $this->post(route('lucky-four.games.winner', [$game, 'verify']), ['ending_bet_id' => $bet])->assertSessionHasErrors('action');

        $this->assertDatabaseCount('lucky_four_winners', 0);
        $this->assertDatabaseCount('player_wallet_transactions', 0);
    }

    public function test_ending_results_compute_the_high_score_digit_and_verified_lucky_winners_lock_corrections(): void
    {
        $this->luckyFixtures();
        $this->luckyAdmin();
        $game = $this->luckyGame();
        $result = DB::table('ending_game_results')->where('game_id', $game)->where('quarter_id', 1)->value('id');
        $bet = $this->luckyBet($game);
        $this->post(route('lucky-four.games.winner', [$game, 'verify']), ['ending_bet_id' => $bet])->assertSessionHasNoErrors();
        $payload = ['quarter_id' => 1, 'team_a_score' => 18, 'team_b_score' => 32, 'reason' => 'Score correction'];

        $this->put(route('ending.games.results.update', [$game, $result]), $payload)->assertSessionHasErrors('result');

        $this->assertDatabaseHas('ending_game_results', ['id' => $result, 'lucky_four_result' => 0]);
        $this->post(route('lucky-four.games.winner', [$game, 'unverify']), ['ending_bet_id' => $bet])->assertSessionHasNoErrors();
        $this->put(route('ending.games.results.update', [$game, $result]), $payload)->assertSessionHasNoErrors();
        $this->assertDatabaseHas('ending_game_results', ['id' => $result, 'lucky_four_result' => 2]);
        $this->assertDatabaseHas('lucky_four_winners', ['ending_bet_id' => $bet, 'winner_status' => 'Unverified']);
    }

    public function test_settings_validate_amounts_and_preserve_existing_game_prizes(): void
    {
        $this->luckyFixtures();
        $this->luckyAdmin();
        $game = $this->luckyGame();
        $values = ['jackpot_prize' => '2000.00', 'initial_amount' => '1500.00', 'three_digits_prize' => '100.00', 'bet_amount' => '30.00', 'percentage_deduction' => '30.00'];

        $this->put(route('lucky-four.settings.update'), $values)->assertSessionHasNoErrors();

        $this->assertDatabaseHas('lucky_four_prizes_settings', $values);
        $this->assertDatabaseHas('lucky_four_prizes', ['game_id' => $game, 'jackpot_prize' => '1000.00']);
        $this->assertDatabaseCount('lucky_four_prizes_setting_logs', 1);
        $this->put(route('lucky-four.settings.update'), ['percentage_deduction' => '101', 'bet_amount' => '0'] + $values)->assertSessionHasErrors(['percentage_deduction', 'bet_amount']);
        $this->assertDatabaseCount('lucky_four_prizes_setting_logs', 1);
    }

    public function test_guest_and_permissions_guard_pages_and_every_write_action(): void
    {
        $this->luckyFixtures();
        $game = $this->luckyGame();
        $this->get(route('lucky-four.games.index'))->assertRedirect('/login');
        $this->luckyAdmin([]);
        foreach (['lucky-four.games.index', 'lucky-four.settings', 'lucky-four.bets.index', 'lucky-four.bets.export'] as $route) {
            $this->get(route($route))->assertForbidden();
        }
        $this->get(route('lucky-four.games.show', $game))->assertForbidden();
        $this->put(route('lucky-four.games.toggle', $game), ['enabled' => false])->assertForbidden();
        $this->put(route('lucky-four.settings.update'))->assertForbidden();
        $this->post(route('lucky-four.games.refresh', $game))->assertForbidden();
        foreach (['verify', 'unverify', 'approve'] as $action) {
            $this->post(route('lucky-four.games.winner', [$game, $action]))->assertForbidden();
        }
        $this->assertDatabaseCount('admin_lucky_four_audits', 0);
    }

    public function test_cross_game_ticket_and_concurrent_ending_writer_are_rejected(): void
    {
        $this->luckyFixtures();
        $this->luckyAdmin();
        $game = $this->luckyGame();
        $other = $this->luckyGame();
        $bet = $this->luckyBet($other);
        $this->post(route('lucky-four.games.winner', [$game, 'verify']), ['ending_bet_id' => $bet])->assertNotFound();
        $lock = Cache::lock('ending:write', 30);
        $lock->get();

        $this->post(route('lucky-four.games.winner', [$other, 'verify']), ['ending_bet_id' => $bet])->assertSessionHasErrors('game');

        $lock->release();
        $this->assertDatabaseCount('lucky_four_winners', 0);
    }

    public function test_pages_filter_enabled_games_and_export_bets_with_manila_dates_and_safe_csv(): void
    {
        $this->luckyFixtures();
        $this->luckyAdmin();
        $game = $this->luckyGame();
        $this->luckyBet($game);
        DB::table('players')->where('id', 9)->update(['name' => '=FORMULA()']);
        $other = $this->luckyGame();
        DB::table('ending_games')->where('id', $other)->update(['is_lucky_four' => 'No']);

        $this->get(route('lucky-four.games.index'))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('lucky-four/Games')->has('games.data', 1));
        $this->get(route('lucky-four.games.show', $other))->assertNotFound();
        $this->get(route('lucky-four.settings'))->assertOk();
        $this->get(route('lucky-four.bets.index', ['game_id' => $game]))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('lucky-four/Bets')->has('bets.data', 1)->where('bets.data.0.combination', '0123')->missing('bets.data.0.legacy_checksum'));
        $response = $this->get(route('lucky-four.bets.export', ['game_id' => $game, 'date_from' => '2026-11-01', 'date_to' => '2026-11-01']))->assertOk();
        $csv = $response->streamedContent();
        $this->assertStringContainsString("'=FORMULA()", $csv);
        $this->assertStringContainsString("'0123", $csv);
        $this->assertStringContainsString('2026-11-01 00:30:00', $csv);
        $this->get(route('lucky-four.bets.index', ['per_page' => 100000]))->assertSessionHasErrors('per_page');
    }

    public function test_historical_released_prize_is_never_paid_again(): void
    {
        $this->luckyFixtures();
        $this->luckyAdmin();
        $game = $this->luckyGame();
        $bet = $this->luckyBet($game);
        DB::table('lucky_four_winners')->insert(['ending_bet_id' => $bet, 'winner_status' => 'Approved', 'prize_released' => 'Yes', 'prize_amount' => '1000.00', 'legacy_id' => 99]);

        $this->post(route('lucky-four.games.winner', [$game, 'approve']), ['ending_bet_id' => $bet])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('player_wallet', ['actual_amount' => '100.00']);
        $this->assertDatabaseCount('player_wallet_transactions', 0);
        $this->assertDatabaseCount('admin_lucky_four_settlements', 0);
    }

    public function test_standalone_lucky_four_ticket_appears_in_bets_and_receives_its_prize_without_a_card(): void
    {
        $this->luckyFixtures();
        $this->luckyAdmin();
        $game = $this->luckyGame();
        $bet = $this->luckyBet($game);
        DB::table('ending_bets')->where('id', $bet)->update(['card_id' => 0, 'game_type_id' => 3]);
        DB::table('player_wallet')->delete();

        $this->get(route('lucky-four.bets.index'))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->has('bets.data', 1));
        $this->post(route('lucky-four.games.winner', [$game, 'verify']), ['ending_bet_id' => $bet])->assertSessionHasNoErrors();
        $this->post(route('lucky-four.games.winner', [$game, 'approve']), ['ending_bet_id' => $bet])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('player_wallet', ['player_id' => 9, 'actual_amount' => '1000.00']);
        $this->assertDatabaseCount('player_wallet_transactions', 1);
    }

    public function test_approval_requires_verification_and_refuses_changed_prizes_and_refunded_tickets(): void
    {
        $this->luckyFixtures();
        $this->luckyAdmin();
        $game = $this->luckyGame();
        $bet = $this->luckyBet($game);
        $this->post(route('lucky-four.games.winner', [$game, 'approve']), ['ending_bet_id' => $bet])->assertSessionHasErrors('action');
        $this->post(route('lucky-four.games.winner', [$game, 'verify']), ['ending_bet_id' => $bet])->assertSessionHasNoErrors();
        DB::table('lucky_four_prizes')->where('game_id', $game)->update(['jackpot_prize' => '2000.00']);
        $this->post(route('lucky-four.games.winner', [$game, 'approve']), ['ending_bet_id' => $bet])->assertSessionHasErrors('action');
        DB::table('lucky_four_prizes')->where('game_id', $game)->update(['jackpot_prize' => '1000.00']);
        DB::table('ending_bets')->where('id', $bet)->update(['refunded' => 'Yes']);

        $this->post(route('lucky-four.games.winner', [$game, 'approve']), ['ending_bet_id' => $bet])->assertSessionHasErrors('action');

        $this->assertDatabaseHas('lucky_four_winners', ['ending_bet_id' => $bet, 'winner_status' => 'Verified', 'prize_released' => 'No']);
        $this->assertDatabaseHas('player_wallet', ['actual_amount' => '100.00']);
        $this->assertDatabaseCount('player_wallet_transactions', 0);
    }
}
