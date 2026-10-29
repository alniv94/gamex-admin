<?php

namespace Tests\Feature;

use App\Models\AdminEndingLog;
use App\Models\EndingConfig;
use App\Models\EndingGame;
use App\Models\EndingLeague;
use App\Models\EndingTeam;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\Support\EndingFixtures;
use Tests\TestCase;

class EndingCatalogTest extends TestCase
{
    use EndingFixtures, RefreshDatabase;

    public function test_catalog_pages_require_their_own_legacy_permissions(): void
    {
        $this->endingFixtures();
        $this->get('/ending/teams')->assertRedirect('/login');
        $this->get('/ending/leagues')->assertRedirect('/login');
        $this->get('/ending/config')->assertRedirect('/login');
        $this->endingAdmin([17]);
        foreach ([
            ['get', '/ending/teams'], ['post', '/ending/teams'], ['put', '/ending/teams/1'],
            ['get', '/ending/leagues'], ['post', '/ending/leagues'], ['put', '/ending/leagues/1'],
            ['get', '/ending/config'], ['post', '/ending/config'], ['put', '/ending/config/1'],
            ['post', '/ending/presets'], ['put', '/ending/presets/2/1'], ['delete', '/ending/presets/2/1'],
        ] as [$method, $url]) {
            $this->{$method}($url)->assertForbidden();
        }
        $this->assertDatabaseCount('admin_ending_logs', 0);
    }

    public function test_team_search_includes_alias_and_league_and_paginates_without_private_archives(): void
    {
        $this->endingFixtures();
        $this->endingAdmin([18]);
        EndingTeam::query()->findOrFail(1)->update(['team_alias' => 'Blues', 'logo' => 'https://example.test/blue.png']);
        $this->get('/ending/teams?search=Blues&per_page=10')->assertInertia(fn (AssertableInertia $page) => $page
            ->component('ending/Teams')->has('records.data', 1)->where('records.per_page', 10)
            ->where('records.data.0.logo', 'https://example.test/blue.png')->where('records.data.0.league.league', 'Test League')
            ->missing('records.data.0.legacy_checksum')->missing('records.data.0.legacy_dates'));
        $this->get('/ending/teams?search=Test%20League')->assertInertia(fn (AssertableInertia $page) => $page->where('records.total', 2));
        $this->get('/ending/teams?per_page=0')->assertSessionHasErrors('per_page');
    }

    public function test_leagues_and_teams_can_be_created_and_edited_with_atomic_audits(): void
    {
        $this->endingFixtures();
        $user = $this->endingAdmin([18, 19]);
        $this->post('/ending/leagues', ['league' => 'PBA', 'logo' => 'https://example.test/pba.png', 'is_local' => 'Local', 'is_active' => 'Active'])
            ->assertRedirect('/ending/leagues')->assertSessionHasNoErrors();
        $league = EndingLeague::query()->where('league', 'PBA')->sole();
        $this->post('/ending/teams', ['league_id' => $league->id, 'team_name' => 'Manila', 'team_alias' => 'Stars', 'logo' => 'https://example.test/stars.png', 'is_active' => 'Active'])
            ->assertRedirect('/ending/teams')->assertSessionHasNoErrors();
        $team = EndingTeam::query()->where('team_name', 'Manila')->sole();
        $this->put('/ending/teams/'.$team->id, ['league_id' => $league->id, 'team_name' => 'Manila', 'team_alias' => 'Comets', 'logo' => 'https://example.test/comets.png', 'is_active' => 'Inactive'])
            ->assertRedirect('/ending/teams')->assertSessionHasNoErrors();
        $this->put('/ending/leagues/'.$league->id, ['league' => 'PBA', 'logo' => 'https://example.test/new-pba.png', 'is_local' => 'International', 'is_active' => 'Inactive'])
            ->assertRedirect('/ending/leagues')->assertSessionHasNoErrors();
        $this->assertSame('Comets', $team->fresh()->team_alias);
        $this->assertSame('Inactive', $team->fresh()->is_active);
        $this->assertSame('International', $league->fresh()->is_local);
        $this->assertDatabaseCount('admin_ending_logs', 4);
        $audit = AdminEndingLog::query()->where('action', 'update_team')->sole();
        $this->assertNull($audit->game_id);
        $this->assertSame($user->id, $audit->user_id);
        $this->assertSame('Stars', $audit->before['team_alias']);
        $this->assertSame('Comets', $audit->after['team_alias']);
        $this->get('/ending/leagues?search=PBA')->assertInertia(fn (AssertableInertia $page) => $page->component('ending/Leagues')->where('records.total', 1));
    }

    public function test_duplicate_names_invalid_parents_and_unsafe_logo_urls_are_rejected(): void
    {
        $this->endingFixtures();
        $this->endingAdmin([18, 19]);
        $this->post('/ending/teams', ['league_id' => 1, 'team_name' => 'Blue Team', 'logo' => 'javascript:alert(1)', 'is_active' => 'Active'])
            ->assertSessionHasErrors(['team_name', 'logo']);
        $this->post('/ending/teams', ['league_id' => 9999, 'team_name' => 'New Team', 'logo' => 'https://example.test/logo.png', 'is_active' => 'Invalid'])
            ->assertSessionHasErrors(['league_id', 'is_active']);
        $this->post('/ending/leagues', ['league' => 'Test League', 'logo' => 'data:image/svg+xml,unsafe', 'is_local' => 'Other', 'is_active' => 'Active'])
            ->assertSessionHasErrors(['league', 'logo', 'is_local']);
        $this->assertDatabaseCount('admin_ending_logs', 0);
    }

    public function test_config_only_exposes_and_edits_supported_ending_settings(): void
    {
        $this->endingFixtures();
        $this->endingAdmin([20]);
        $private = DB::table('ending_config')->insertGetId(['config_name' => 'Global OTP', 'config_value' => 'private-value']);
        $this->get('/ending/config')->assertInertia(fn (AssertableInertia $page) => $page->component('ending/Config')->has('records', 4)->has('presets', 1))
            ->assertDontSee('private-value');
        $this->put('/ending/config/'.$private, ['config_name' => 'Global OTP', 'config_value' => '123'])->assertNotFound();
        $this->post('/ending/config', ['config_name' => 'Game Betting Disabled', 'config_desc' => 'Minutes before tipoff', 'config_value' => '30'])
            ->assertRedirect('/ending/config')->assertSessionHasNoErrors();
        $config = EndingConfig::query()->where('config_name', 'Game Betting Disabled')->sole();
        $this->put('/ending/config/'.$config->id, ['config_name' => $config->config_name, 'config_desc' => 'Cutoff', 'config_value' => '45'])
            ->assertSessionHasNoErrors();
        $this->assertSame('45', $config->fresh()->config_value);
        $this->put('/ending/config/'.$config->id, ['config_name' => 'Card Allow Open', 'config_value' => '-1'])
            ->assertSessionHasErrors(['config_name', 'config_value']);
        $this->assertSame('45', $config->fresh()->config_value);
    }

    public function test_presets_keep_types_separate_and_preserve_existing_game_snapshots(): void
    {
        $this->endingFixtures();
        $this->endingAdmin([17, 20, 32]);
        $this->travelTo(now()->setDate(2026, 10, 29)->setTime(8, 0));
        $this->post('/ending/games', ['team_a' => 1, 'team_b' => 2, 'schedule' => '2026-10-30T18:00'])->assertSessionHasNoErrors();
        $first = EndingGame::query()->sole();
        $this->put('/ending/presets/2/1', ['amount' => '25.00', 'card_quantity' => 3, 'status' => 'Active'])
            ->assertRedirect('/ending/config')->assertSessionHasNoErrors();
        $this->post('/ending/presets', ['bet_type_id' => 1, 'amount' => '25.00', 'card_quantity' => 0, 'status' => 'Active'])
            ->assertSessionHasNoErrors();
        $this->post('/ending/presets', ['bet_type_id' => 2, 'amount' => '35.00', 'card_quantity' => 10, 'status' => 'Inactive'])
            ->assertSessionHasNoErrors();
        $config = EndingConfig::query()->where('config_name', 'Per Quarter Card - 1st Quarter Prize Percent')->sole();
        $this->put('/ending/config/'.$config->id, ['config_name' => $config->config_name, 'config_value' => '12'])->assertSessionHasNoErrors();
        $this->post('/ending/games', ['team_a' => 1, 'team_b' => 2, 'schedule' => '2026-10-30T19:00'])->assertSessionHasNoErrors();
        $second = EndingGame::query()->latest('id')->firstOrFail();
        $this->assertSame(2, $first->cards()->count());
        $this->assertSame('15.00', $first->betAmounts()->sole()->amount);
        $this->assertDatabaseHas('ending_bet_amount_prizes', ['bet_amount_id' => $first->betAmounts()->sole()->id, 'quarter_id' => 1, 'cash_prize' => '150.00']);
        $this->assertSame(3, $second->cards()->count());
        $this->assertDatabaseHas('ending_bet_amount_prizes', ['bet_amount_id' => $second->betAmounts()->sole()->id, 'quarter_id' => 1, 'cash_prize' => '300.00']);
        $this->delete('/ending/presets/2/1')->assertRedirect('/ending/config')->assertSessionHasNoErrors();
        $this->assertDatabaseMissing('ending_per_quarter_bet_amount_config', ['id' => 1]);
        $this->assertSame(3, $second->cards()->count());
        $this->assertDatabaseHas('admin_ending_logs', ['action' => 'delete_preset', 'subject_type' => 'ending_per_quarter_bet_amount_config', 'subject_id' => 1]);
    }

    public function test_invalid_preset_values_and_duplicate_amounts_are_rejected(): void
    {
        $this->endingFixtures();
        $this->endingAdmin([20]);
        $this->post('/ending/presets', ['bet_type_id' => 2, 'amount' => '15.00', 'card_quantity' => 1, 'status' => 'Active'])->assertSessionHasErrors('amount');
        $this->post('/ending/presets', ['bet_type_id' => 2, 'amount' => '0.001', 'card_quantity' => 1001, 'status' => 'Other'])->assertSessionHasErrors(['amount', 'card_quantity', 'status']);
        $this->post('/ending/presets', ['bet_type_id' => 3, 'amount' => 25, 'card_quantity' => 1, 'status' => 'Active'])->assertSessionHasErrors('bet_type_id');
        $this->put('/ending/presets/2/1', ['amount' => '25.00', 'card_quantity' => -1, 'status' => 'Active'])->assertSessionHasErrors('card_quantity');
        $this->put('/ending/presets/1/999', ['amount' => '25.00', 'card_quantity' => 1, 'status' => 'Active'])->assertNotFound();
        $this->assertDatabaseCount('admin_ending_logs', 0);
        $this->assertDatabaseHas('ending_per_quarter_bet_amount_config', ['id' => 1, 'amount' => '15.00', 'card_quantity' => 2]);
    }

    public function test_catalog_writes_obey_import_verification_and_shared_write_lock(): void
    {
        $this->endingFixtures();
        $this->endingAdmin([19]);
        $data = ['league' => 'PBA', 'logo' => 'https://example.test/pba.png', 'is_local' => 'Local', 'is_active' => 'Active'];
        $lock = Cache::lock('ending:write', 30);
        $lock->get();
        try {
            $this->post('/ending/leagues', $data)->assertSessionHasErrors('game');
        } finally {
            $lock->release();
        }
        DB::table('admin_ending_imports')->delete();
        $this->post('/ending/leagues', $data)->assertSessionHasErrors('game');
        $this->assertDatabaseCount('ending_leagues', 1);
        $this->assertDatabaseCount('admin_ending_logs', 0);
    }

    public function test_catalog_record_and_audit_roll_back_together(): void
    {
        $this->endingFixtures();
        $this->endingAdmin([19]);
        DB::statement('CREATE TRIGGER reject_catalog_audit BEFORE INSERT ON admin_ending_logs BEGIN SELECT RAISE(ABORT, "audit unavailable"); END');
        $this->post('/ending/leagues', ['league' => 'PBA', 'logo' => 'https://example.test/pba.png', 'is_local' => 'Local', 'is_active' => 'Active'])
            ->assertServerError();
        $this->assertDatabaseMissing('ending_leagues', ['league' => 'PBA']);
    }

    public function test_subtraction_only_disables_unused_cards_and_filters_stay_scoped_to_the_game(): void
    {
        $this->endingFixtures();
        $this->endingAdmin([17, 12]);
        $this->freezeTime();
        $game = EndingGame::factory()->create(['team_a' => 1, 'team_b' => 2, 'schedule' => now()->addDay()]);
        $amount = $game->betAmounts()->create(['bet_type_id' => 2, 'amount' => '15.00']);
        $used = $amount->cards()->create(['betting' => 'Enabled']);
        $unused = $amount->cards()->create(['betting' => 'Enabled']);
        DB::table('ending_bets')->insert(['card_id' => $used->id, 'player_id' => 9, 'slot_id' => 11]);
        DB::table('ending_bets')->insert(['card_id' => $used->id, 'player_id' => 9, 'slot_id' => 12]);
        DB::table('ending_config')->insert(['config_name' => 'Card Allow Open', 'config_value' => '1']);
        $url = route('ending.games.cards.subtract', ['game' => $game, 'betAmount' => $amount]);
        $this->put($url, ['quantity' => 2])->assertSessionHasErrors('quantity');
        $this->assertSame('Enabled', $unused->fresh()->betting);
        $this->put($url, ['quantity' => 1])->assertRedirect(route('ending.games.show', $game))->assertSessionHasNoErrors();
        $this->assertSame('Disabled', $unused->fresh()->betting);
        $this->assertSame('Enabled', $used->fresh()->betting);
        $this->assertDatabaseCount('ending_cards', 2);
        $this->assertDatabaseHas('admin_ending_logs', ['action' => 'subtract_card', 'game_id' => $game->id]);
        $other = EndingGame::factory()->create(['team_a' => 1, 'team_b' => 2]);
        $this->put(route('ending.games.cards.subtract', ['game' => $other, 'betAmount' => $amount]), ['quantity' => 1])->assertNotFound();
        $this->get(route('ending.games.show', ['game' => $other, 'bet_amount_id' => $amount->id]))->assertNotFound();
        $this->get(route('ending.games.show', ['game' => $game, 'bet_amount_id' => $amount->id]))->assertInertia(fn (AssertableInertia $page) => $page
            ->where('cardAmountFilter', $amount->id)->has('cards.data', 2)->where('amounts.0.disabled_cards', 1)->where('amounts.0.exceeded_cards', 1)->where('cardThreshold', 1));
        $this->travelTo(now()->addDays(2));
        $this->put($url, ['quantity' => 1])->assertSessionHasErrors('cards');
    }

    public function test_scheduled_table_sorts_paginates_and_includes_team_logos(): void
    {
        $this->endingFixtures();
        $this->endingAdmin([17]);
        $this->freezeTime();
        EndingTeam::query()->findOrFail(1)->update(['logo' => 'https://example.test/blue.png']);
        foreach (range(1, 6) as $offset) {
            EndingGame::factory()->create(['team_a' => 1, 'team_b' => 2, 'schedule' => now()->addDays($offset), 'created_at' => now()->addSeconds($offset)]);
        }
        $this->get('/ending/games?per_page=5')->assertInertia(fn (AssertableInertia $page) => $page
            ->has('games.data', 5)->where('games.total', 6)->where('games.data.0.id', 6)
            ->where('games.data.0.team_a_logo', 'https://example.test/blue.png')->missing('games.data.0.legacy_checksum'));
        $this->get('/ending/games?per_page=5&sort=created_at&direction=asc')->assertInertia(fn (AssertableInertia $page) => $page->where('games.data.0.id', 1));
        $this->get('/ending/games?sort=untrusted_column')->assertSessionHasErrors('sort');
    }
}
