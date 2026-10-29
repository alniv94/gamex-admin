<?php

namespace Tests\Feature;

use App\Models\AdminEndingLog;
use App\Models\EndingGame;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\Support\EndingFixtures;
use Tests\TestCase;

class EndingAuditTest extends TestCase
{
    use EndingFixtures, RefreshDatabase;

    public function test_creation_records_the_selected_presets_and_original_prizes(): void
    {
        $this->endingFixtures();
        $this->endingAdmin();
        $this->travelTo(now()->setDate(2026, 10, 29)->setTime(8, 0));
        DB::table('ending_per_quarter_bet_amount_config')->insert(['id' => 2, 'amount' => '25.00', 'card_quantity' => 3]);
        $this->post(route('ending.games.store'), [
            'team_a' => 1, 'team_b' => 2, 'schedule' => '2026-10-30T18:00',
            'presets' => [['bet_type_id' => 2, 'config_id' => 1, 'quantity' => 1], ['bet_type_id' => 2, 'config_id' => 2, 'quantity' => 0]],
        ])->assertSessionHasNoErrors();
        $game = EndingGame::query()->sole();
        $audit = AdminEndingLog::query()->where('action', 'create_game')->sole();
        $this->assertNull($audit->before);
        $this->assertSame(1, $audit->after['cards']);
        $this->assertSame('Blue Team', $audit->after['team_a_name']);
        $this->assertSame('Red Team', $audit->after['team_b_name']);
        $this->assertSame('Test League', $audit->after['league']);
        $this->assertSame(1, $audit->after['presets'][0]['config_id']);
        $this->assertSame($game->betAmounts()->sole()->id, $audit->after['presets'][0]['bet_amount_id']);
        $this->assertSame(2, $audit->after['presets'][0]['bet_type_id']);
        $this->assertSame('15.00', $audit->after['presets'][0]['amount']);
        $this->assertSame(1, $audit->after['presets'][0]['quantity']);
        $this->assertSame('150.00', $audit->after['presets'][0]['prizes'][0]['cash_prize']);
        $this->assertSame('600.00', $audit->after['presets'][0]['prizes'][3]['cash_prize']);
        $this->assertSame(0, $audit->after['presets'][1]['quantity']);
        $this->assertNull($audit->after['presets'][1]['bet_amount_id']);
        DB::table('ending_per_quarter_bet_amount_config')->where('id', 1)->update(['amount' => '99.00', 'card_quantity' => 8]);
        DB::table('ending_config')->update(['config_value' => '99']);
        $this->assertSame('15.00', $audit->fresh()->after['presets'][0]['amount']);
        $this->assertSame('150.00', $audit->fresh()->after['presets'][0]['prizes'][0]['cash_prize']);
    }

    public function test_adding_to_an_existing_preset_records_actual_before_and_after_counts(): void
    {
        $this->endingFixtures();
        $this->endingAdmin();
        $this->travelTo(now()->setDate(2026, 10, 29)->setTime(8, 0));
        $this->post(route('ending.games.store'), ['team_a' => 1, 'team_b' => 2, 'schedule' => '2026-10-30T18:00'])->assertSessionHasNoErrors();
        $game = EndingGame::query()->sole();
        $amount = $game->betAmounts()->sole();
        $otherAmount = $game->betAmounts()->create(['bet_type_id' => 1, 'amount' => '20.00']);
        $otherAmount->cards()->create(['betting' => 'Enabled']);
        DB::table('ending_per_quarter_bet_amount_config')->where('id', 1)->update(['card_quantity' => 90]);
        $this->post(route('ending.games.cards', $game), ['bet_amount_id' => $amount->id, 'quantity' => 12])
            ->assertRedirect(route('ending.games.show', $game))->assertSessionHasNoErrors();
        $audit = AdminEndingLog::query()->where('action', 'add_card')->sole();
        $this->assertSame(2, $audit->before['cards']);
        $this->assertSame(14, $audit->after['cards']);
        $this->assertSame(3, $audit->before['game_cards']);
        $this->assertSame(15, $audit->after['game_cards']);
        $this->assertSame(12, $audit->after['cards_added']);
        $this->assertSame('15.00', $audit->before['amount']);
        $this->assertSame($audit->before['prizes'], $audit->after['prizes']);
        $this->assertSame(14, $amount->cards()->count());
        $this->get(route('ending.games.show', $game))->assertInertia(fn (AssertableInertia $page) => $page
            ->where('logs.data.0.before.cards', 2)->where('logs.data.0.after.cards', 14)
            ->where('logs.data.0.after.cards_added', 12));
    }

    public function test_a_new_bet_amount_has_a_zero_card_baseline_and_preserves_non_cash_prizes(): void
    {
        $this->endingFixtures();
        $this->endingAdmin();
        $this->travelTo(now()->setDate(2026, 10, 29)->setTime(8, 0));
        $this->post(route('ending.games.store'), ['team_a' => 1, 'team_b' => 2, 'schedule' => '2026-10-30T18:00'])->assertSessionHasNoErrors();
        $game = EndingGame::query()->sole();
        $this->post(route('ending.games.cards', $game), [
            'bet_type_id' => 1, 'amount' => '50.00', 'quantity' => 3,
            'prizes' => array_map(fn (int $quarter) => ['quarter_id' => $quarter, 'is_cash' => 'Not Cash', 'other_prize' => 'Gift voucher'], [1, 2, 3, 4]),
        ])->assertSessionHasNoErrors();
        $audit = AdminEndingLog::query()->where('action', 'add_card')->sole();
        $this->assertSame(['cards' => 0, 'game_cards' => 2], $audit->before);
        $this->assertSame(3, $audit->after['cards']);
        $this->assertSame(5, $audit->after['game_cards']);
        $this->assertSame('50.00', $audit->after['amount']);
        $this->assertSame('Gift voucher', $audit->after['prizes'][0]['other_prize']);
        $this->assertNull($audit->after['prizes'][0]['cash_prize']);
    }

    public function test_a_card_addition_and_its_snapshot_roll_back_if_the_audit_cannot_be_saved(): void
    {
        $this->endingFixtures();
        $this->endingAdmin();
        $this->travelTo(now()->setDate(2026, 10, 29)->setTime(8, 0));
        $this->post(route('ending.games.store'), ['team_a' => 1, 'team_b' => 2, 'schedule' => '2026-10-30T18:00'])->assertSessionHasNoErrors();
        $game = EndingGame::query()->sole();
        $amount = $game->betAmounts()->sole();
        DB::statement('CREATE TRIGGER reject_card_audit BEFORE INSERT ON admin_ending_logs BEGIN SELECT RAISE(ABORT, "audit unavailable"); END');
        $this->post(route('ending.games.cards', $game), ['bet_amount_id' => $amount->id, 'quantity' => 12])->assertServerError();
        $this->assertSame(2, $amount->cards()->count());
        $this->assertDatabaseCount('admin_ending_logs', 1);
    }

    public function test_reading_older_logs_does_not_reconstruct_or_rewrite_missing_snapshots(): void
    {
        $this->endingFixtures();
        $this->endingAdmin();
        $game = EndingGame::factory()->create(['team_a' => 1, 'team_b' => 2]);
        $audit = AdminEndingLog::query()->create([
            'game_id' => $game->id, 'action' => 'add_card', 'subject_type' => 'bet_amount', 'subject_id' => 11,
            'before' => null, 'after' => ['quantity' => 12], 'created_at' => now(),
        ]);
        $this->get(route('ending.games.show', $game))->assertInertia(fn (AssertableInertia $page) => $page
            ->where('logs.data.0.before', null)->where('logs.data.0.after.quantity', 12));
        $this->assertNull($audit->fresh()->before);
        $this->assertSame(['quantity' => 12], $audit->fresh()->after);
    }
}
