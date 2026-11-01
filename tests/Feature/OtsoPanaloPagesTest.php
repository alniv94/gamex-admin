<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\Support\OtsoPanaloFixtures;
use Tests\TestCase;

class OtsoPanaloPagesTest extends TestCase
{
    use OtsoPanaloFixtures, RefreshDatabase;

    public function test_pages_require_authentication_and_writes_require_their_own_permissions(): void
    {
        $this->otsoFixtures();
        $game = $this->otsoGame();
        $this->get(route('otso.games.index'))->assertRedirect(route('login'));
        $this->otsoAdmin([]);
        $this->get(route('otso.games.index'))->assertForbidden();

        $this->otsoAdmin(['Otso Panalo']);
        foreach (['otso.results.store', 'otso.results.approve', 'otso.winners.generate', 'otso.winners.approve', 'otso.games.cancel', 'otso.bets.import'] as $route) {
            $this->post(route($route, $game), [])->assertForbidden();
        }
        $this->put(route('otso.games.betting', $game), ['enabled' => true])->assertForbidden();
        $this->get(route('otso.bets.pdf', $game))->assertForbidden();

        $this->assertDatabaseCount('admin_otso_panalo_audits', 0);
        $this->assertDatabaseCount('player_wallet_transactions', 0);
    }

    public function test_games_details_configurations_and_bets_render_without_archived_private_payloads(): void
    {
        $this->otsoFixtures();
        $this->otsoAdmin();
        $game = $this->otsoGame(['legacy_payload' => '{"secret":"archive"}']);
        $this->otsoBet($game);

        $this->get(route('otso.games.index'))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('otso/Games')
            ->has('games.data', 1)->where('games.data.0.jackpot_prize', 1000)->missing('games.data.0.legacy_payload'));
        $this->get(route('otso.games.show', $game))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('otso/Game')->where('game.id', $game)->missing('game.legacy_payload')->where('summary.bets', 1));
        $this->get(route('otso.configs.index'))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('otso/Configs')->has('configs.data', 1));
        $this->get(route('otso.bets.index'))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('otso/Bets')->has('bets.data', 1)->where('bets.data.0.username', 'otso_player'));
    }

    public function test_bets_filter_by_manila_calendar_date_game_search_and_pagination(): void
    {
        $this->otsoFixtures();
        $this->otsoAdmin();
        $game = $this->otsoGame();
        $other = $this->otsoGame();
        $included = $this->otsoBet($game, 1, ['created_at' => '2026-10-31 16:05:00', 'name' => 'Selected Ticket']);
        $this->otsoBet($game, 1, ['created_at' => '2026-10-31 15:59:00']);
        $this->otsoBet($other, 1, ['created_at' => '2026-10-31 16:05:00']);

        $this->get(route('otso.bets.index', ['game_id' => $game, 'date_from' => '2026-11-01', 'search' => 'Selected', 'per_page' => 1]))
            ->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('bets.total', 1)->where('bets.data.0.id', $included)->where('bets.per_page', 1));
        $this->get(route('otso.bets.index', ['game_id' => $game, 'filter_by' => 'Game Schedule', 'date_from' => '2026-11-02']))
            ->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('bets.total', 2));
        $this->get(route('otso.bets.index', ['search' => "' OR 1=1 --"]))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('bets.total', 0));
        $this->get(route('otso.bets.index', ['per_page' => 501]))->assertSessionHasErrors('per_page');
    }

    public function test_games_use_production_pagination_and_sort_across_the_filtered_dataset(): void
    {
        $this->otsoFixtures();
        $this->otsoAdmin();
        $older = $this->otsoGame(['created_at' => '2026-10-29 00:00:00', 'bet_amount' => '5.00']);
        $newer = $this->otsoGame(['created_at' => '2026-10-30 00:00:00', 'bet_amount' => '20.00']);

        $this->get(route('otso.games.index'))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('games.per_page', 10)->where('games.data.0.id', $newer));
        $this->get(route('otso.games.index', ['sort' => 'bet_amount', 'direction' => 'asc', 'per_page' => 1]))
            ->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('games.data.0.id', $older)->where('games.total', 2)->where('filters.sort', 'bet_amount'));
        $this->get(route('otso.games.index', ['sort' => 'bet_amount', 'direction' => 'asc', 'per_page' => 1, 'page' => 2]))
            ->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('games.data.0.id', $newer));
        $this->get(route('otso.games.index', ['sort' => 'bet_amount', 'direction' => 'desc']))
            ->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('games.data.0.id', $newer));
        $this->get(route('otso.games.index', ['sort' => 'g.id; DROP TABLE users']))->assertSessionHasErrors('sort');
        $this->get(route('otso.games.index', ['direction' => 'invalid']))->assertSessionHasErrors('direction');
    }

    public function test_details_financial_totals_exclude_refunded_tickets_and_other_games(): void
    {
        $this->otsoFixtures();
        $this->otsoAdmin();
        $game = $this->otsoGame();
        $paid = $this->otsoBet($game, 3, ['amount' => '20.10']);
        $this->otsoBet($game, 0, ['amount' => '30.20']);
        $refunded = $this->otsoBet($game, 2, ['amount' => '40.30', 'refunded' => 'Yes']);
        $other = $this->otsoBet($this->otsoGame(), 4, ['amount' => '500.00']);
        DB::table('otso_panalo_winners')->insert([
            ['bet_id' => $paid, 'winner_type_id' => 2, 'prize_amount' => '100.25'],
            ['bet_id' => $refunded, 'winner_type_id' => 3, 'prize_amount' => '50.00'],
            ['bet_id' => $other, 'winner_type_id' => 1, 'prize_amount' => '1000.00'],
        ]);

        $this->get(route('otso.games.show', $game))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('summary.bets', 3)->where('summary.gross', 50.3)->where('summary.winnings', 100.25)
            ->where('summary.refunded', 40.3));
    }

    public function test_winner_and_result_read_permissions_limit_detail_props(): void
    {
        $this->otsoFixtures();
        $this->otsoAdmin(['Otso Panalo']);
        $game = $this->otsoGame();
        $this->otsoApprovedResult($game);

        $this->get(route('otso.games.show', $game))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('result', null)->where('winners', null)->where('scores', [])->where('canViewResult', false));
    }

    public function test_winner_approval_cannot_target_another_games_ticket(): void
    {
        $this->otsoFixtures();
        $this->otsoAdmin();
        $game = $this->otsoGame();
        $other = $this->otsoGame();
        $bet = $this->otsoBet($other, 3);
        $this->otsoApprovedResult($game);
        $winner = DB::table('otso_panalo_winners')->insertGetId(['bet_id' => $bet, 'winner_type_id' => 2, 'prize_amount' => '100.00']);

        $this->post(route('otso.winners.approve', $game), ['winner_id' => $winner])->assertNotFound();

        $this->assertDatabaseHas('otso_panalo_winners', ['id' => $winner, 'winner_status' => 'Verified']);
        $this->assertDatabaseCount('player_wallet_transactions', 0);
    }

    public function test_csv_export_includes_all_filtered_rows_and_protects_spreadsheet_values(): void
    {
        $this->otsoFixtures();
        $this->otsoAdmin();
        $game = $this->otsoGame();
        $this->otsoBet($game, 4, ['name' => '=HYPERLINK("bad")']);
        $this->otsoBet($game, 2);
        $this->otsoBet($this->otsoGame(), 1, ['name' => 'Another game']);

        $response = $this->get(route('otso.bets.export', ['game_id' => $game, 'per_page' => 1]));

        $response->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $csv = $response->streamedContent();
        $this->assertStringContainsString("'=HYPERLINK", $csv);
        $this->assertStringContainsString("'1-2", $csv);
        $this->assertStringNotContainsString('Another game', $csv);
        $this->assertCount(3, explode("\n", trim($csv)));
    }

    public function test_pdf_download_includes_all_game_bets_and_escapes_ticket_text(): void
    {
        $this->otsoFixtures();
        $this->otsoAdmin();
        $game = $this->otsoGame();
        $this->otsoBet($game, 4, ['name' => '<script>alert(1)</script>', 'created_at' => '2026-04-01 00:00:00']);
        $this->otsoBet($game, 0, ['refunded' => 'Yes']);

        $response = $this->get(route('otso.bets.pdf', $game));

        $response->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', $response->getContent());
        $html = view('otso.bets', ['generated_date' => 'Nov 01, 2026', 'details' => ['id' => $game],
            'schedule' => '-', 'betting_deadline' => '-', 'total_bet_amount' => 40,
            'bets' => [['id' => 1, 'username' => 'otso_player', 'bet_type' => 'Straight', 'name' => '<script>alert(1)</script>', 'first_quarter_combination' => '1-2', 'second_quarter_combination' => '2-3', 'third_quarter_combination' => '3-4', 'fourth_quarter_combination' => '4-5', 'amount' => 20, 'created_at' => '2026-04-01 00:00:00']]])->render();
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('Apr 01, 2026 08:00 AM', $html);
    }
}
