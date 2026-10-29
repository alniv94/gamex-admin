<?php

namespace Tests\Feature;

use App\Models\AdminEndingLog;
use App\Models\EndingGame;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Support\SessionKey;
use Inertia\Testing\AssertableInertia;
use Tests\Support\EndingFixtures;
use Tests\TestCase;

class EndingInertiaTest extends TestCase
{
    use EndingFixtures, RefreshDatabase;

    public function test_creation_redirects_to_the_game_with_an_inertia_success_message(): void
    {
        $this->endingFixtures();
        $this->endingAdmin();
        $this->travelTo(now()->setDate(2026, 10, 29)->setTime(8, 0));

        $response = $this->post(route('ending.games.store'), [
            'team_a' => 1, 'team_b' => 2, 'schedule' => '2026-10-30T18:00',
        ]);

        $game = EndingGame::query()->sole();
        $response->assertRedirect(route('ending.games.show', $game))
            ->assertSessionHasNoErrors()->assertSessionHas(SessionKey::FLASH_DATA.'.success', 'Game created.');
        $this->assertSame('2026-10-30T10:00:00+00:00', $game->schedule->toIso8601String());
        $this->get(route('ending.games.show', $game))->assertInertia(fn (AssertableInertia $page) => $page
            ->component('ending/Game')->where('game.schedule', '2026-10-30T10:00:00+00:00')
            ->where('game.team_a_name', 'Blue Team')->where('game.league', 'Test League')->has('cards.data', 2));
    }

    public function test_invalid_inertia_form_keeps_validation_errors_and_creates_no_game(): void
    {
        $this->endingFixtures();
        $this->endingAdmin();
        $this->travelTo(now()->setDate(2026, 10, 29)->setTime(8, 0));

        $this->from('/ending/games')->post(route('ending.games.store'), [
            'team_a' => 1, 'team_b' => 1, 'schedule' => '2026-10-30T18:00',
        ])->assertRedirect('/ending/games')->assertSessionHasErrors('team_b');

        $this->assertDatabaseCount('ending_games', 0);
        $this->assertDatabaseCount('admin_ending_logs', 0);
    }

    public function test_scoped_binding_refuses_cards_and_results_from_another_game(): void
    {
        $this->endingFixtures();
        $this->endingAdmin();
        $game = EndingGame::factory()->create(['team_a' => 1, 'team_b' => 2]);
        $other = EndingGame::factory()->create(['team_a' => 1, 'team_b' => 2]);
        $amount = $game->betAmounts()->create(['amount' => '15.00', 'bet_type_id' => 2]);
        $card = $amount->cards()->create(['betting' => 'Enabled']);
        $result = $game->results()->create(['quarter_id' => 1, 'team_a_score' => 21, 'team_b_score' => 30, 'slot_id' => 11]);

        $this->put(route('ending.games.card_betting', ['game' => $other, 'card' => $card]), ['enabled' => false])->assertNotFound();
        $this->put(route('ending.games.results.update', ['game' => $other, 'result' => $result]), [
            'quarter_id' => 1, 'team_a_score' => 22, 'team_b_score' => 30, 'reason' => 'Wrong game',
        ])->assertNotFound();

        $this->assertSame('Enabled', $card->fresh()->betting);
        $this->assertSame(21, $result->fresh()->team_a_score);
        $this->assertDatabaseCount('admin_ending_logs', 0);
    }

    public function test_card_bets_are_optional_inertia_props_with_private_fields_excluded(): void
    {
        $this->endingFixtures();
        $this->endingAdmin();
        $game = EndingGame::factory()->create(['team_a' => 1, 'team_b' => 2, 'legacy_checksum' => str_repeat('a', 64)]);
        $amount = $game->betAmounts()->create(['amount' => '15.00', 'bet_type_id' => 2]);
        $card = $amount->cards()->create(['betting' => 'Enabled']);
        DB::table('ending_bets')->insert([
            'player_id' => 9, 'card_id' => $card->id, 'slot_id' => 11, 'quarter_id' => 0,
            'ip_address' => '192.0.2.1', 'geo_lat' => 'private-latitude', 'legacy_checksum' => str_repeat('b', 64),
        ]);

        $this->get(route('ending.games.show', ['game' => $game, 'card_id' => $card->id]))
            ->assertInertia(fn (AssertableInertia $page) => $page->missing('cardBets')->missing('game.legacy_checksum')
                ->reloadOnly('cardBets', fn (AssertableInertia $partial) => $partial
                    ->has('cardBets', 1)->where('cardBets.0.player_name', 'Test Player')
                    ->where('cardBets.0.combination', '1-0')->missing('cardBets.0.ip_address')
                    ->missing('cardBets.0.geo_lat')->missing('cardBets.0.legacy_checksum')));
    }

    public function test_eloquent_card_updates_preserve_the_before_and_after_audit_values(): void
    {
        $this->endingFixtures();
        $this->endingAdmin();
        $game = EndingGame::factory()->create(['team_a' => 1, 'team_b' => 2]);
        $amount = $game->betAmounts()->create(['amount' => '15.00', 'bet_type_id' => 2]);
        $card = $amount->cards()->create(['betting' => 'Enabled']);

        $this->put(route('ending.games.card_betting', ['game' => $game, 'card' => $card]), ['enabled' => false])
            ->assertRedirect(route('ending.games.show', $game))->assertSessionHasNoErrors();

        $audit = AdminEndingLog::query()->sole();
        $this->assertSame(['betting' => 'Enabled'], $audit->before);
        $this->assertSame(['betting' => 'Disabled'], $audit->after);
        $this->assertSame('Disabled', $card->fresh()->betting);
    }
}
