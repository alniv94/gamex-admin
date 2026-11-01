<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\EndingFixtures;
use Tests\Support\PlayerFixtures;
use Tests\TestCase;

class PlayerReportsTest extends TestCase
{
    use EndingFixtures, PlayerFixtures, RefreshDatabase;

    #[DataProvider('reports')]
    public function test_reports_and_exports_require_an_authenticated_admin_with_player_list_permission(string $report): void
    {
        $this->get('/players/reports/'.$report)->assertRedirect('/login');
        $this->get('/players/reports/'.$report.'/export')->assertRedirect('/login');
        $this->endingAdmin([17]);

        $this->get('/players/reports/'.$report)->assertForbidden();
        $this->get('/players/reports/'.$report.'/export')->assertForbidden();
    }

    public static function reports(): array
    {
        return [['registered'], ['new'], ['active']];
    }

    public function test_registered_defaults_to_all_accounts_including_players_without_profiles(): void
    {
        $this->reportFixtures();
        $this->endingAdmin([1]);
        DB::table('players')->insert(['id' => 12, 'name' => 'No profile', 'created_at' => '2026-10-17 00:00:00']);

        $this->get('/players/reports/registered')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->component('players/Report')->where('title', 'Registered Player List')->where('players.total', 4)
            ->where('players.data.0.id', 12)->where('players.data.0.account_verified', null)
            ->where('filters.status', 'All')->where('filters.account_verified', 'All')
            ->where('filters.date_from', null)->where('filters.date_to', null)
            ->missing('players.data.0.password')->missing('players.data.0.legacy_checksum'));
    }

    public function test_registration_dates_include_whole_manila_days_and_open_ended_ranges(): void
    {
        $this->reportFixtures();
        $this->endingAdmin([1]);

        $this->get('/players/reports/registered?date_from=2026-10-16&date_to=2026-10-16')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('players.total', 2)
                ->where('players.data.0.id', 10)->where('players.data.1.id', 9));
        $this->get('/players/reports/registered?date_to=2026-10-15')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('players.total', 1)->where('players.data.0.id', 11));
        $this->get('/players/reports/registered?date_from=2026-10-17')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('players.total', 0));
    }

    public function test_new_players_default_to_today_in_manila_and_apply_search_and_status(): void
    {
        $this->reportFixtures();
        $this->endingAdmin([1]);
        $this->travelTo(new \DateTimeImmutable('2026-10-15 16:30:00 UTC'));

        $this->get('/players/reports/new')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('title', 'Newly Registered Players')->where('today', '2026-10-16')
            ->where('filters.date_from', '2026-10-16')->where('filters.date_to', '2026-10-16')->where('players.total', 2));
        $this->get('/players/reports/new?search=bravo&status=Inactive&account_verified=Verified&sort=name&direction=asc')
            ->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('players.total', 1)
            ->where('players.data.0.id', 10)->where('players.data.0.city_municipality', 'Manila'));
        $this->get('/players/reports/new?search=bravo&status=Active')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('players.total', 0));
    }

    #[DataProvider('searchTerms')]
    public function test_search_matches_contact_fields_and_binds_literal_input(string $search, int $total): void
    {
        $this->reportFixtures();
        $this->endingAdmin([1]);

        $this->get('/players/reports/registered?'.http_build_query(['search' => $search]))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('players.total', $total));
    }

    public static function searchTerms(): array
    {
        return ['name' => ['Alpha', 1], 'email' => ['bravo@example.test', 1], 'mobile' => ['456709', 1],
            'zero' => ['0', 2], 'SQL text' => ["' OR 1=1 --", 0]];
    }

    public function test_active_totals_use_separate_bet_and_winner_dates_without_multiplying_amounts(): void
    {
        $this->activityFixtures();
        $this->endingAdmin([1]);
        $this->travelTo(new \DateTimeImmutable('2026-10-16 08:00:00 UTC'));

        $this->get('/players/reports/active')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('title', 'Active Players Summary')->where('players.total', 2)
            ->where('players.data.0.id', 9)->where('players.data.0.total_bet_count', 3)
            ->where('players.data.0.ending_amount', fn ($value) => (float) $value === 60.0)
            ->where('players.data.0.lucky_four_amount', fn ($value) => (float) $value === 7.5)
            ->where('players.data.0.total_bets', fn ($value) => (float) $value === 67.5)
            ->where('players.data.0.total_payouts', fn ($value) => (float) $value === 150.0)
            ->where('players.data.0.net_amount', fn ($value) => (float) $value === -82.5)
            ->where('players.data.1.id', 10)->where('players.data.1.total_bet_count', 1)
            ->where('players.data.1.total_payouts', fn ($value) => (float) $value === 0.0)->where('luckyFourReady', false));
        $this->get('/players/reports/active?search=Manila&sort=net_amount&direction=asc')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('players.total', 2)->where('players.data.0.id', 9));
        $this->get('/players/reports/active?search=Charlie')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('players.total', 0));
    }

    public function test_pagination_and_exports_use_the_same_filters_and_protect_spreadsheet_cells(): void
    {
        $this->reportFixtures();
        $this->endingAdmin([1]);
        foreach (range(12, 16) as $id) {
            DB::table('players')->insert(['id' => $id, 'name' => $id === 12 ? '=HYPERLINK("example")' : 'Export '.$id,
                'created_at' => '2026-10-15 16:00:00']);
        }
        $params = ['date_from' => '2026-10-16', 'date_to' => '2026-10-16', 'per_page' => 5, 'page' => 2, 'sort' => 'id', 'direction' => 'asc'];

        $this->get('/players/reports/new?'.http_build_query($params))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('players.total', 7)->has('players.data', 2)
                ->where('players.data.0.id', 15)->where('players.data.1.id', 16)
                ->where('players.prev_page_url', fn (string $url) => str_contains($url, 'date_from=2026-10-16')));
        $response = $this->get('/players/reports/new/export?'.http_build_query($params + ['scope' => 'page']))->assertOk();
        $rows = $this->csvRows($response->streamedContent());
        $this->assertCount(3, $rows);
        $this->assertSame('15', $rows[1][0]);
        $all = $this->get('/players/reports/new/export?'.http_build_query($params + ['scope' => 'all']))->assertOk();
        $rows = $this->csvRows($all->streamedContent());
        $this->assertCount(8, $rows);
        $this->assertSame("'=HYPERLINK(\"example\")", $rows[3][1]);
        $this->assertSame("'+639123456709", $rows[1][2]);
        $this->assertSame('2026-10-16 00:00:00', $rows[1][6]);
    }

    public function test_active_export_matches_totals_and_verified_history_clears_the_warning(): void
    {
        $this->activityFixtures();
        $this->endingAdmin([1]);
        DB::table('admin_player_report_imports')->insert(['source' => config('players.legacy_source'), 'row_count' => 2, 'verified_at' => now()]);

        $response = $this->get('/players/reports/active/export?date_from=2026-10-16&date_to=2026-10-16&search=Alpha')->assertOk();
        $rows = $this->csvRows($response->streamedContent());
        $this->assertCount(2, $rows);
        $this->assertSame('67.5', $rows[1][9]);
        $this->assertSame('150', $rows[1][10]);
        $this->assertSame("'-82.5", $rows[1][11]);
        $this->get('/players/reports/active?date_from=2026-10-17&date_to=2026-10-17&search=absent')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('players.total', 0)->where('luckyFourReady', true));
    }

    #[DataProvider('invalidFilters')]
    public function test_invalid_report_filters_are_rejected(string $report, array $filters, string $field): void
    {
        $this->endingAdmin([1]);

        $this->getJson('/players/reports/'.$report.'?'.http_build_query($filters))
            ->assertUnprocessable()->assertJsonValidationErrors($field);
    }

    public static function invalidFilters(): array
    {
        return [
            ['registered', ['sort' => 'password'], 'sort'], ['active', ['sort' => 'id; DROP TABLE players'], 'sort'],
            ['registered', ['direction' => 'sideways'], 'direction'], ['new', ['per_page' => 5000], 'per_page'],
            ['registered', ['page' => 0], 'page'], ['registered', ['date_from' => '2026-02-30'], 'date_from'],
            ['registered', ['date_from' => '2026-10-17', 'date_to' => '2026-10-16'], 'date_to'],
            ['new', ['date_from' => ''], 'date_from'], ['active', ['date_to' => ''], 'date_to'],
            ['registered', ['status' => 'Enabled'], 'status'], ['new', ['account_verified' => 'Invalid'], 'account_verified'],
            ['registered', ['search' => str_repeat('a', 101)], 'search'],
        ];
    }

    private function reportFixtures(): void
    {
        $this->playerFixtures();
        DB::table('cities')->insert(['id' => 1, 'name' => 'Manila']);
        DB::table('barangays')->insert(['id' => 1, 'name' => 'Barangay One', 'city_id' => 1]);
        DB::table('player_profiles')->update(['barangay_id' => 1, 'gender' => 'Female']);
        DB::table('players')->where('id', 9)->update(['name' => 'Alpha Player', 'created_at' => '2026-10-15 16:00:00']);
        DB::table('players')->where('id', 10)->update(['name' => 'Bravo Player', 'email' => 'bravo@example.test', 'status' => 'Inactive', 'created_at' => '2026-10-16 15:59:59']);
        DB::table('player_profiles')->where('player_id', 10)->update(['kyc_status' => 'Verified']);
        DB::table('players')->where('id', 11)->update(['name' => 'Charlie Player', 'created_at' => '2026-10-15 15:59:59']);
    }

    private function activityFixtures(): void
    {
        $this->reportFixtures();
        DB::table('ending_bet_amounts')->insert(['id' => 1, 'game_id' => 1, 'bet_type_id' => 1, 'amount' => '20.00']);
        DB::table('ending_cards')->insert(['id' => 1, 'bet_amount_id' => 1]);
        foreach ([[1, 9, '2026-10-15 16:00:00', 1], [2, 9, '2026-10-16 15:59:59', 1],
            [3, 9, '2026-10-15 15:59:59', 1], [4, 9, '2026-10-16 16:00:00', 1],
            [5, 10, '2026-10-16 08:00:00', 1], [6, null, '2026-10-16 08:00:00', 1],
            [7, 9, '2026-10-16 08:00:00', 2], [8, 11, '2026-10-14 08:00:00', 1]] as [$id, $player, $date, $type]) {
            DB::table('ending_bets')->insert(['id' => $id, 'player_id' => $player, 'card_id' => 1, 'slot_id' => $id, 'game_type_id' => $type, 'created_at' => $date]);
        }
        DB::table('lucky_four_bets')->insert([
            ['id' => 1, 'ending_bet_id' => 1, 'amount' => '5.00'], ['id' => 2, 'ending_bet_id' => 2, 'amount' => '2.50'],
            ['id' => 3, 'ending_bet_id' => 3, 'amount' => '100.00'], ['id' => 4, 'ending_bet_id' => 4, 'amount' => '100.00'],
        ]);
        foreach ([[1, 'Cash', '100'], [2, 'Cash', '50'], [3, 'Other', '999']] as [$quarter, $kind, $amount]) {
            DB::table('ending_bet_amount_prizes')->insert(['bet_amount_id' => 1, 'quarter_id' => $quarter, 'is_cash' => $kind, 'cash_prize' => $amount]);
        }
        foreach ([[1, 1, 1, 'Yes', '2026-10-15 16:00:00'], [2, 3, 2, 'Yes', '2026-10-16 15:59:59'],
            [3, 1, 3, 'Yes', '2026-10-16 08:00:00'], [4, 2, 1, 'No', '2026-10-16 08:00:00'],
            [5, 2, 2, 'Yes', '2026-10-16 16:00:00'], [6, 7, 1, 'Yes', '2026-10-16 08:00:00'],
            [7, 8, 1, 'Yes', '2026-10-16 08:00:00']] as [$id, $bet, $quarter, $released, $date]) {
            DB::table('ending_game_winners')->insert(['id' => $id, 'bet_id' => $bet, 'quarter_id' => $quarter, 'prize_released' => $released, 'created_at' => $date]);
        }
    }

    private function csvRows(string $csv): array
    {
        return array_map(fn (string $line) => str_getcsv($line, escape: ''), explode("\n", trim(substr($csv, 3))));
    }
}
