<?php

namespace Tests\Feature;

use App\Models\AdminProfile;
use App\Models\User;
use App\Services\SummaryReports;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class SummaryReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get(route('summary-report.index'))->assertRedirect('/login');
    }

    public function test_admins_without_players_summary_permission_receive_403(): void
    {
        $this->admin(false);

        $this->get(route('summary-report.index'))->assertForbidden();
    }

    public function test_report_returns_yearly_registration_and_bet_totals_in_ascending_order(): void
    {
        $this->fixtures();
        $this->admin();
        $this->travelTo(new \DateTimeImmutable('2026-11-05 04:00:00 UTC'));
        DB::table('players')->insert([
            ['name' => 'Inactive player', 'status' => 'Inactive', 'created_at' => '2022-06-01 00:00:00'],
            ['name' => 'Active player', 'status' => 'Active', 'created_at' => '2022-06-02 00:00:00'],
            ['name' => 'No bets', 'status' => 'Active', 'created_at' => '2024-06-01 00:00:00'],
        ]);
        $this->bet('2022-06-01 00:00:00', '20.25', '5.50');
        $this->bet('2022-07-01 00:00:00', '40.10');
        $this->bet('2023-06-01 00:00:00', '10.00', '2.00');

        $this->get(route('summary-report.index'))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->component('SummaryReport')->where('records', [
                ['year' => 2022, 'total_players' => 2, 'bet_count' => 2, 'bet_amount' => '65.85'],
                ['year' => 2023, 'total_players' => 0, 'bet_count' => 1, 'bet_amount' => '12.00'],
                ['year' => 2024, 'total_players' => 1, 'bet_count' => 0, 'bet_amount' => '0.00'],
            ]));
    }

    public function test_years_use_manila_time_and_bets_start_at_the_beginning_of_2022(): void
    {
        $this->fixtures();
        $this->admin();
        $this->travelTo(new \DateTimeImmutable('2026-11-05 04:00:00 UTC'));
        DB::table('players')->insert([
            ['name' => 'Before midnight', 'created_at' => '2022-12-31 15:59:59'],
            ['name' => 'At midnight', 'created_at' => '2022-12-31 16:00:00'],
        ]);
        $this->bet('2021-12-31 15:59:59', '100.00');
        $this->bet('2021-12-31 16:00:00', '10.00');
        $this->bet('2022-12-31 15:59:59', '20.00');
        $this->bet('2022-12-31 16:00:00', '30.00');

        $this->get(route('summary-report.index'))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('records', [
                ['year' => 2022, 'total_players' => 1, 'bet_count' => 2, 'bet_amount' => '30.00'],
                ['year' => 2023, 'total_players' => 1, 'bet_count' => 1, 'bet_amount' => '30.00'],
            ]));
    }

    public function test_future_and_undated_records_are_excluded_but_current_records_are_included(): void
    {
        $this->fixtures();
        $this->admin();
        $this->travelTo(new \DateTimeImmutable('2026-11-05 04:00:00 UTC'));
        DB::table('players')->insert([
            ['name' => 'Current', 'created_at' => '2026-11-05 04:00:00'],
            ['name' => 'Future', 'created_at' => '2026-11-05 04:00:01'],
            ['name' => 'Undated', 'created_at' => null],
        ]);
        $this->bet('2026-11-05 04:00:00', '15.00');
        $this->bet('2026-11-05 04:00:01', '100.00');
        $this->bet(null, '100.00');

        $this->get(route('summary-report.index'))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('records', [
                ['year' => 2026, 'total_players' => 1, 'bet_count' => 1, 'bet_amount' => '15.00'],
            ]));
    }

    public function test_bets_with_missing_card_amounts_are_counted_and_lucky_four_is_still_included(): void
    {
        $this->fixtures();
        $this->admin();
        $this->travelTo(new \DateTimeImmutable('2026-11-05 04:00:00 UTC'));
        $this->bet('2026-01-01 00:00:00', null, '5.25');
        DB::table('ending_bets')->insert([
            'card_id' => 999, 'slot_id' => 1, 'created_at' => '2026-01-02 00:00:00',
        ]);

        $this->get(route('summary-report.index'))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('records', [
                ['year' => 2026, 'total_players' => 0, 'bet_count' => 2, 'bet_amount' => '5.25'],
            ]));
    }

    public function test_empty_database_returns_an_empty_report(): void
    {
        $this->fixtures();
        $this->admin();

        $this->get(route('summary-report.index'))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->component('SummaryReport')->where('records', []));
    }

    public function test_shared_cards_count_each_bet_and_keep_prices_and_lucky_four_in_the_correct_year(): void
    {
        $this->fixtures();
        $this->admin();
        $this->travelTo(new \DateTimeImmutable('2026-11-05 04:00:00 UTC'));
        $amountId = DB::table('ending_bet_amounts')->insertGetId([
            'game_id' => 1, 'bet_type_id' => 1, 'amount' => '20.25',
        ]);
        $cardId = DB::table('ending_cards')->insertGetId(['bet_amount_id' => $amountId]);
        foreach (['2022-12-31 15:59:58', '2022-12-31 15:59:59', '2022-12-31 16:00:00'] as $index => $date) {
            $betId = DB::table('ending_bets')->insertGetId([
                'card_id' => $cardId, 'slot_id' => $index + 1, 'created_at' => $date,
            ]);
            if ($index !== 1) {
                DB::table('lucky_four_bets')->insert(['ending_bet_id' => $betId, 'amount' => '5.50']);
            }
        }

        $this->get(route('summary-report.index'))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('records', [
                ['year' => 2022, 'total_players' => 0, 'bet_count' => 2, 'bet_amount' => '46.00'],
                ['year' => 2023, 'total_players' => 0, 'bet_count' => 1, 'bet_amount' => '25.75'],
            ]));
    }

    public function test_partial_reload_returns_updated_report_totals(): void
    {
        $this->fixtures();
        $this->admin();
        $this->travelTo(new \DateTimeImmutable('2026-11-05 04:00:00 UTC'));
        $response = $this->get(route('summary-report.index'));
        $this->bet('2026-11-05 04:00:00', '20.00', '5.00');

        $response->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('records', [])
            ->reloadOnly('records', fn (AssertableInertia $reload) => $reload->where('records', [
                ['year' => 2026, 'total_players' => 0, 'bet_count' => 1, 'bet_amount' => '25.00'],
            ])));
    }

    private function admin(bool $permitted = true): void
    {
        $user = User::factory()->create(['status' => 'Active']);
        AdminProfile::create([
            'user_id' => $user->id, 'legacy_admin_id' => $user->id,
            'source_checksum' => str_repeat('a', 64), 'legacy_payload' => ['fixture' => true],
        ]);
        if ($permitted) {
            $user->givePermissionTo(Permission::findOrCreate('Players Summary', 'web'));
        }
        $this->actingAs($user)->withSession(['admin_version' => $user->profile->session_version]);
    }

    public function test_registration_inserts_corrections_and_deletes_update_the_stored_year_totals(): void
    {
        $this->fixtures();
        $this->travelTo(new \DateTimeImmutable('2026-11-05 04:00:00 UTC'));
        $id = DB::table('players')->insertGetId(['name' => 'New player', 'created_at' => '2022-12-31 15:59:59']);
        $this->assertDatabaseHas('admin_summary_reports', ['year' => 2022, 'total_players' => 1]);

        DB::table('players')->where('id', $id)->update(['created_at' => '2022-12-31 16:00:00']);
        $this->assertDatabaseHas('admin_summary_reports', ['year' => 2022, 'total_players' => 0]);
        $this->assertDatabaseHas('admin_summary_reports', ['year' => 2023, 'total_players' => 1]);

        DB::table('players')->where('id', $id)->delete();
        $this->assertSame([], app(SummaryReports::class)->rows());
    }

    public function test_moving_and_deleting_bets_adjusts_both_the_base_price_and_lucky_four(): void
    {
        $this->fixtures();
        $this->travelTo(new \DateTimeImmutable('2026-11-05 04:00:00 UTC'));
        $this->bet('2022-06-01 00:00:00', '20.25', '5.50');
        $bet = DB::table('ending_bets')->first();
        $amount = DB::table('ending_bet_amounts')->insertGetId(['game_id' => 1, 'bet_type_id' => 1, 'amount' => '40.25']);
        $card = DB::table('ending_cards')->insertGetId(['bet_amount_id' => $amount]);
        DB::table('ending_bets')->where('id', $bet->id)->update(['card_id' => $card, 'created_at' => '2023-06-01 00:00:00']);

        $this->assertSame([
            ['year' => 2023, 'total_players' => 0, 'bet_count' => 1, 'bet_amount' => '45.75'],
        ], app(SummaryReports::class)->rows());

        DB::table('ending_bets')->where('id', $bet->id)->delete();
        DB::table('lucky_four_bets')->delete();
        $this->assertSame([], app(SummaryReports::class)->rows());
    }

    public function test_lucky_four_corrections_and_reassignments_do_not_change_bet_counts(): void
    {
        $this->fixtures();
        $this->travelTo(new \DateTimeImmutable('2026-11-05 04:00:00 UTC'));
        $this->bet('2022-06-01 00:00:00', '20.00', '5.00');
        $this->bet('2023-06-01 00:00:00', '30.00');
        $other = DB::table('ending_bets')->orderByDesc('id')->value('id');
        DB::table('lucky_four_bets')->update(['ending_bet_id' => $other, 'amount' => '7.50']);

        $this->assertSame([
            ['year' => 2022, 'total_players' => 0, 'bet_count' => 1, 'bet_amount' => '20.00'],
            ['year' => 2023, 'total_players' => 0, 'bet_count' => 1, 'bet_amount' => '37.50'],
        ], app(SummaryReports::class)->rows());

        DB::table('lucky_four_bets')->delete();
        $this->assertDatabaseHas('admin_summary_reports', ['year' => 2023, 'bet_count' => 1, 'bet_amount' => '30.00']);
    }

    public function test_card_and_price_changes_update_all_affected_bets_without_changing_counts(): void
    {
        $this->fixtures();
        $this->travelTo(new \DateTimeImmutable('2026-11-05 04:00:00 UTC'));
        $this->bet('2022-06-01 00:00:00', '20.00', '5.00');
        $card = DB::table('ending_cards')->first();
        DB::table('ending_bets')->insert(['card_id' => $card->id, 'slot_id' => 2, 'created_at' => '2022-06-02 00:00:00']);
        DB::table('ending_bet_amounts')->where('id', $card->bet_amount_id)->update(['amount' => '30.25']);
        $this->assertDatabaseHas('admin_summary_reports', ['year' => 2022, 'bet_count' => 2, 'bet_amount' => '65.50']);

        $other = DB::table('ending_bet_amounts')->insertGetId(['game_id' => 1, 'bet_type_id' => 1, 'amount' => '10.00']);
        DB::table('ending_cards')->where('id', $card->id)->update(['bet_amount_id' => $other]);
        $this->assertDatabaseHas('admin_summary_reports', ['year' => 2022, 'bet_count' => 2, 'bet_amount' => '25.00']);

        DB::table('ending_bet_amounts')->where('id', $other)->delete();
        $this->assertDatabaseHas('admin_summary_reports', ['year' => 2022, 'bet_count' => 2, 'bet_amount' => '5.00']);
    }

    public function test_repairing_and_removing_historical_card_references_updates_only_the_amount(): void
    {
        $this->fixtures();
        $this->travelTo(new \DateTimeImmutable('2026-11-05 04:00:00 UTC'));
        DB::table('ending_bets')->insert(['card_id' => 999, 'slot_id' => 1, 'created_at' => '2022-06-01 00:00:00']);
        DB::table('ending_cards')->insert(['id' => 999, 'bet_amount_id' => 999]);
        DB::table('ending_bet_amounts')->insert(['id' => 999, 'game_id' => 1, 'bet_type_id' => 1, 'amount' => '25.00']);
        $this->assertDatabaseHas('admin_summary_reports', ['year' => 2022, 'bet_count' => 1, 'bet_amount' => '25.00']);

        DB::table('ending_cards')->where('id', 999)->delete();
        $this->assertDatabaseHas('admin_summary_reports', ['year' => 2022, 'bet_count' => 1, 'bet_amount' => '0.00']);
    }

    public function test_rebuilding_existing_data_is_repeatable_and_leaves_incremental_updates_active(): void
    {
        $this->fixtures();
        $this->travelTo(new \DateTimeImmutable('2026-11-05 04:00:00 UTC'));
        $this->bet('2022-06-01 00:00:00', '20.00', '5.00');
        DB::table('players')->insert(['name' => 'Existing player', 'created_at' => '2022-06-01 00:00:00']);
        DB::table('admin_summary_reports')->update(['bet_count' => 999, 'bet_amount' => '999.00']);

        $this->artisan('summary-report:rebuild')->assertSuccessful();
        $this->artisan('summary-report:rebuild')->assertSuccessful();
        $this->bet('2022-06-01 00:00:00', '10.00');

        $this->assertSame([
            ['year' => 2022, 'total_players' => 1, 'bet_count' => 2, 'bet_amount' => '35.00'],
        ], app(SummaryReports::class)->rows());
    }

    public function test_rolled_back_bets_do_not_leave_summary_totals_behind(): void
    {
        $this->fixtures();
        $this->travelTo(new \DateTimeImmutable('2026-11-05 04:00:00 UTC'));
        DB::beginTransaction();
        $this->bet('2022-06-01 00:00:00', '20.00', '5.00');
        $this->assertDatabaseHas('admin_summary_reports', ['year' => 2022, 'bet_count' => 1, 'bet_amount' => '25.00']);
        DB::rollBack();

        $this->assertSame([], app(SummaryReports::class)->rows());
        $this->assertDatabaseCount('ending_bets', 0);
    }

    public function test_future_dated_records_are_added_once_their_timestamp_is_reached(): void
    {
        $this->fixtures();
        $this->travelTo(new \DateTimeImmutable('2026-11-05 04:00:00 UTC'));
        $this->bet('2026-11-05 04:00:01', '20.00', '5.00');
        DB::table('players')->insert(['name' => 'Future registration', 'created_at' => '2026-11-05 04:00:01']);
        $this->assertSame([], app(SummaryReports::class)->rows());

        $this->travelTo(new \DateTimeImmutable('2026-11-05 04:00:01 UTC'));
        $this->assertSame([
            ['year' => 2026, 'total_players' => 1, 'bet_count' => 1, 'bet_amount' => '25.00'],
        ], app(SummaryReports::class)->rows());
        $this->assertSame(null, DB::table('admin_summary_report_state')->value('next_refresh_at'));
    }

    public function test_loading_the_report_does_not_query_the_source_player_or_betting_tables(): void
    {
        $this->fixtures();
        $this->admin();
        $this->travelTo(new \DateTimeImmutable('2026-11-05 04:00:00 UTC'));
        $this->bet('2022-06-01 00:00:00', '20.00', '5.00');
        DB::enableQueryLog();
        DB::flushQueryLog();

        $this->get(route('summary-report.index'))->assertOk();
        $sourceQueries = array_filter(DB::getQueryLog(), fn (array $query): bool => (bool) preg_match(
            '/\b(players|ending_bets|ending_cards|ending_bet_amounts|lucky_four_bets|otso_panalo_bets)\b/i', $query['query'],
        ));
        DB::disableQueryLog();

        $this->assertSame([], array_values($sourceQueries));
    }

    private function fixtures(): void
    {
        Schema::create('players', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('status')->default('Active');
            $table->timestamps();
        });
        app(SummaryReports::class)->installTriggers();
    }

    public function test_otso_bets_are_added_to_ending_and_lucky_four_totals_and_include_otso_only_years(): void
    {
        $this->fixtures();
        $this->admin();
        $this->travelTo(new \DateTimeImmutable('2026-11-05 04:00:00 UTC'));
        $this->bet('2022-06-01 00:00:00', '20.25', '5.50');
        $this->otsoBet('2022-06-01 00:00:00', '30.25');
        $this->otsoBet('2023-06-01 00:00:00', '40.10');

        $this->get(route('summary-report.index'))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('records', [
                ['year' => 2022, 'total_players' => 0, 'bet_count' => 2, 'bet_amount' => '56.00'],
                ['year' => 2023, 'total_players' => 0, 'bet_count' => 1, 'bet_amount' => '40.10'],
            ]));
    }

    public function test_otso_amount_and_year_corrections_and_deletes_update_the_stored_totals(): void
    {
        $this->fixtures();
        $this->travelTo(new \DateTimeImmutable('2026-11-05 04:00:00 UTC'));
        $bet = $this->otsoBet('2022-12-31 15:59:59', '20.25');
        DB::table('otso_panalo_bets')->where('id', $bet)->update(['amount' => '30.50', 'created_at' => '2022-12-31 16:00:00']);

        $this->assertSame([
            ['year' => 2023, 'total_players' => 0, 'bet_count' => 1, 'bet_amount' => '30.50'],
        ], app(SummaryReports::class)->rows());

        DB::table('otso_panalo_bets')->where('id', $bet)->update(['refunded' => 'Yes']);
        $this->assertDatabaseHas('admin_summary_reports', ['year' => 2023, 'bet_count' => 1, 'bet_amount' => '30.50']);
        DB::table('otso_panalo_bets')->where('id', $bet)->delete();
        $this->assertSame([], app(SummaryReports::class)->rows());
    }

    public function test_otso_dates_use_manila_years_and_exclude_undated_and_future_bets_until_due(): void
    {
        $this->fixtures();
        $this->travelTo(new \DateTimeImmutable('2026-11-05 04:00:00 UTC'));
        $this->otsoBet('2021-12-31 15:59:59', '100.00');
        $this->otsoBet('2021-12-31 16:00:00', '10.00');
        $this->otsoBet('2022-12-31 15:59:59', '20.00');
        $this->otsoBet('2022-12-31 16:00:00', '30.00');
        $this->otsoBet('2026-11-05 04:00:00', null);
        $this->otsoBet('2026-11-05 04:00:01', '100.00');
        $this->otsoBet(null, '100.00');
        $this->assertSame([
            ['year' => 2022, 'total_players' => 0, 'bet_count' => 2, 'bet_amount' => '30.00'],
            ['year' => 2023, 'total_players' => 0, 'bet_count' => 1, 'bet_amount' => '30.00'],
            ['year' => 2026, 'total_players' => 0, 'bet_count' => 1, 'bet_amount' => '0.00'],
        ], app(SummaryReports::class)->rows());

        $this->travelTo(new \DateTimeImmutable('2026-11-05 04:00:01 UTC'));
        $this->assertSame([
            ['year' => 2022, 'total_players' => 0, 'bet_count' => 2, 'bet_amount' => '30.00'],
            ['year' => 2023, 'total_players' => 0, 'bet_count' => 1, 'bet_amount' => '30.00'],
            ['year' => 2026, 'total_players' => 0, 'bet_count' => 2, 'bet_amount' => '100.00'],
        ], app(SummaryReports::class)->rows());
    }

    public function test_historical_otso_bets_are_backfilled_once_and_new_bets_keep_updating_the_summary(): void
    {
        $this->fixtures();
        $this->travelTo(new \DateTimeImmutable('2026-11-05 04:00:00 UTC'));
        DB::unprepared('DROP TRIGGER admin_summary_otso_panalo_bets_insert');
        $this->otsoBet('2023-06-01 00:00:00', '20.25');
        $this->assertSame([], app(SummaryReports::class)->rows());

        $this->artisan('summary-report:rebuild')->assertSuccessful();
        $this->artisan('summary-report:rebuild')->assertSuccessful();
        $this->otsoBet('2023-06-02 00:00:00', '30.25');

        $this->assertSame([
            ['year' => 2023, 'total_players' => 0, 'bet_count' => 2, 'bet_amount' => '50.50'],
        ], app(SummaryReports::class)->rows());
    }

    public function test_rolled_back_otso_bets_do_not_change_the_summary(): void
    {
        $this->fixtures();
        $this->travelTo(new \DateTimeImmutable('2026-11-05 04:00:00 UTC'));
        DB::beginTransaction();
        $this->otsoBet('2023-06-01 00:00:00', '20.25');
        $this->assertDatabaseHas('admin_summary_reports', ['year' => 2023, 'bet_count' => 1, 'bet_amount' => '20.25']);
        DB::rollBack();

        $this->assertSame([], app(SummaryReports::class)->rows());
        $this->assertDatabaseCount('otso_panalo_bets', 0);
    }

    private function otsoBet(?string $createdAt, ?string $amount): int
    {
        $game = DB::table('otso_panalo_games')->value('id');
        if ($game === null) {
            $status = DB::table('otso_panalo_statuses')->insertGetId(['name' => 'Scheduled']);
            $ending = DB::table('ending_games')->insertGetId([
                'team_a' => 1, 'team_b' => 2, 'schedule' => '2026-11-06 04:00:00',
            ]);
            $game = DB::table('otso_panalo_games')->insertGetId(['ending_game_id' => $ending, 'game_status_id' => $status]);
        }
        $type = DB::table('otso_panalo_bet_types')->value('id')
            ?? DB::table('otso_panalo_bet_types')->insertGetId(['bet_type' => 'Straight']);

        return DB::table('otso_panalo_bets')->insertGetId([
            'game_id' => $game, 'bet_type_id' => $type, 'created_at' => $createdAt, 'amount' => $amount,
        ]);
    }

    private function bet(?string $createdAt, ?string $amount, ?string $luckyFour = null): void
    {
        $amountId = DB::table('ending_bet_amounts')->insertGetId([
            'game_id' => 1, 'bet_type_id' => 1, 'amount' => $amount,
        ]);
        $cardId = DB::table('ending_cards')->insertGetId(['bet_amount_id' => $amountId]);
        $betId = DB::table('ending_bets')->insertGetId([
            'card_id' => $cardId, 'slot_id' => 1, 'created_at' => $createdAt,
        ]);
        if ($luckyFour !== null) {
            DB::table('lucky_four_bets')->insert(['ending_bet_id' => $betId, 'amount' => $luckyFour]);
        }
    }
}
