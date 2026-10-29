<?php

namespace Tests\Feature;

use App\Models\EndingGame;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Testing\AssertableInertia;
use Tests\Support\EndingFixtures;
use Tests\TestCase;

class EndingCardPageTest extends TestCase
{
    use EndingFixtures, RefreshDatabase;

    public function test_card_page_requires_an_authenticated_administrator_with_view_permission(): void
    {
        $this->endingFixtures();
        $game = EndingGame::factory()->create(['team_a' => 1, 'team_b' => 2]);
        $url = route('ending.games.cards.index', $game);

        $this->get($url)->assertRedirect(route('login'));
        $this->endingAdmin([12]);
        $this->get($url)->assertForbidden();
    }

    public function test_card_page_shows_the_selected_amount_matchup_and_bet_counts(): void
    {
        $this->endingFixtures();
        $this->endingAdmin([17]);
        $game = EndingGame::factory()->create(['team_a' => 1, 'team_b' => 2]);
        $amount = $game->betAmounts()->create(['amount' => '15.25', 'bet_type_id' => 2]);
        $card = $amount->cards()->create(['betting' => 'Enabled']);
        $otherAmount = $game->betAmounts()->create(['amount' => '10.00', 'bet_type_id' => 1]);
        $otherAmount->cards()->create(['betting' => 'Disabled']);
        DB::table('ending_bets')->insert([
            ['card_id' => $card->id, 'slot_id' => 11, 'quarter_id' => 0, 'refunded' => 'No'],
            ['card_id' => $card->id, 'slot_id' => 12, 'quarter_id' => 0, 'refunded' => 'Yes'],
        ]);

        $this->get(route('ending.games.cards.index', ['game' => $game, 'bet_amount_id' => $amount->id]))
            ->assertInertia(fn (AssertableInertia $page) => $page->component('ending/Cards')
                ->where('game.id', $game->id)->where('game.league', 'Test League')
                ->where('game.team_a_name', 'Blue Team')->where('game.team_b_name', 'Red Team')
                ->where('amount', ['id' => $amount->id, 'amount' => '15.25', 'bet_type_id' => 2])
                ->where('cards.total', 1)->has('cards.data', 1)->where('cards.data.0.id', $card->id)
                ->where('cards.data.0.bets', 2)->where('cards.data.0.amount', '15.25')->missing('cardBets')
                ->missing('game.legacy_checksum')->missing('cards.data.0.legacy_checksum'));

        $this->get(route('ending.games.cards.index', $game))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('amount', null)->where('cards.total', 2));
    }

    public function test_card_search_and_pagination_keep_the_selected_bet_amount(): void
    {
        $this->endingFixtures();
        $this->endingAdmin([17]);
        $game = EndingGame::factory()->create(['team_a' => 1, 'team_b' => 2]);
        $amount = $game->betAmounts()->create(['amount' => '15.00', 'bet_type_id' => 2]);
        $cards = $amount->cards()->createMany(array_fill(0, 6, ['betting' => 'Enabled']));
        $otherAmount = $game->betAmounts()->create(['amount' => '10.00', 'bet_type_id' => 1]);
        $otherAmount->cards()->create(['betting' => 'Enabled']);
        $url = route('ending.games.cards.index', ['game' => $game, 'bet_amount_id' => $amount->id]);

        $this->get($url.'&per_page=5&page=2')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('cards.total', 6)
                ->where('cards.per_page', 5)->has('cards.data', 1)->where('cards.data.0.id', $cards->last()->id));
        $this->get($url.'&search=%23'.str_pad((string) $cards->first()->id, 6, '0', STR_PAD_LEFT))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('cards.total', 1)
                ->where('cards.data.0.id', $cards->first()->id));
        $this->get($url.'&search=missing')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('cards.total', 0)->has('cards.data', 0));
        $this->get($url.'&per_page=7')->assertSessionHasErrors('per_page');
    }

    public function test_card_inspection_returns_bets_without_private_import_and_location_data(): void
    {
        $this->endingFixtures();
        $this->endingAdmin([17]);
        $game = EndingGame::factory()->create(['team_a' => 1, 'team_b' => 2]);
        $amount = $game->betAmounts()->create(['amount' => '15.00', 'bet_type_id' => 2]);
        $card = $amount->cards()->create(['betting' => 'Enabled']);
        DB::table('ending_bets')->insert([
            'card_id' => $card->id, 'slot_id' => 11, 'quarter_id' => 0, 'player_id' => 9,
            'name' => 'Ticket name', 'ip_address' => '192.0.2.1', 'geo_lat' => 'private-latitude',
            'legacy_checksum' => str_repeat('b', 64),
        ]);

        $this->get(route('ending.games.cards.index', ['game' => $game, 'bet_amount_id' => $amount->id, 'card_id' => $card->id]))
            ->assertInertia(fn (AssertableInertia $page) => $page->missing('cardBets')
                ->reloadOnly('cardBets', fn (AssertableInertia $partial) => $partial
                    ->has('cardBets', 1)->where('cardBets.0.player_name', 'Test Player')
                    ->where('cardBets.0.combination', '1-0')->where('cardBets.0.name', 'Ticket name')
                    ->missing('cardBets.0.ip_address')->missing('cardBets.0.geo_lat')->missing('cardBets.0.legacy_checksum')));
        $this->assertDatabaseCount('admin_ending_logs', 0);
    }

    public function test_card_page_refuses_amounts_and_cards_outside_the_selected_game_or_amount(): void
    {
        $this->endingFixtures();
        $this->endingAdmin([17]);
        $game = EndingGame::factory()->create(['team_a' => 1, 'team_b' => 2]);
        $amount = $game->betAmounts()->create(['amount' => '15.00', 'bet_type_id' => 2]);
        $card = $amount->cards()->create(['betting' => 'Enabled']);
        $otherAmount = $game->betAmounts()->create(['amount' => '10.00', 'bet_type_id' => 1]);
        $otherGame = EndingGame::factory()->create(['team_a' => 1, 'team_b' => 2]);

        $this->get(route('ending.games.cards.index', ['game' => $otherGame, 'bet_amount_id' => $amount->id]))->assertNotFound();
        $headers = ['X-Inertia' => 'true', 'X-Inertia-Version' => Inertia::getVersion(), 'X-Inertia-Partial-Component' => 'ending/Cards', 'X-Inertia-Partial-Data' => 'cardBets'];
        $this->get(route('ending.games.cards.index', ['game' => $otherGame, 'card_id' => $card->id]), $headers)->assertNotFound();
        $this->get(route('ending.games.cards.index', ['game' => $game, 'bet_amount_id' => $otherAmount->id, 'card_id' => $card->id]), $headers)->assertNotFound();
    }
}
