<?php

namespace Tests\Feature;

use App\Services\Pagcor\PagcorReports;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Tests\Support\EndingFixtures;
use Tests\Support\PlayerFixtures;
use Tests\TestCase;

class PagcorReportsTest extends TestCase
{
    use EndingFixtures, PlayerFixtures, RefreshDatabase;

    public static function reports(): array
    {
        return array_combine(array_keys(PagcorReports::DEFINITIONS), array_map(fn (string $report): array => [$report], array_keys(PagcorReports::DEFINITIONS)));
    }

    #[DataProvider('reports')]
    public function test_reports_and_exports_require_their_pagcor_permission(string $report): void
    {
        $url = '/pagcor/reports/'.$report;
        $this->get($url)->assertRedirect('/login');
        $this->get($url.'/export')->assertRedirect('/login');
        $this->endingAdmin([17]);

        $this->get($url)->assertForbidden();
        $this->get($url.'/export')->assertForbidden();
    }

    #[DataProvider('reports')]
    public function test_reports_start_empty_and_generate_rows_only_after_submission(string $report): void
    {
        $this->fixtures();
        $this->reportAdmin($report);

        $this->get('/pagcor/reports/'.$report)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->component('pagcor/Report')->where('records.total', 0)->where('filters.submitted', 0)->has('pagcorReportNavigation', 5));
        $this->get('/pagcor/reports/'.$report.'?'.$this->params())->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->component('pagcor/Report')->where('records.total', 1)->where('filters.date_to', '2026-10-16'));
    }

    public function test_ending_financials_use_the_ggr_rate_and_only_released_cash_prizes(): void
    {
        $this->fixtures();
        $this->reportAdmin('ending-game');

        $this->get('/pagcor/reports/ending-game?'.$this->params())->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('records.data.0.gross_sales', fn ($v) => (float) $v === 60.0)
            ->where('records.data.0.payout', fn ($v) => (float) $v === 150.0)
            ->where('records.data.0.ggr', fn ($v) => (float) $v === -90.0)
            ->where('records.data.0.pagcor_share', fn ($v) => (float) $v === -22.5)
            ->loadDeferredProps(fn (AssertableInertia $deferred) => $deferred
                ->where('totals.gross_sales', fn ($v) => (float) $v === 60.0)->where('totals.pagcor_share', fn ($v) => (float) $v === -22.5)));
    }

    public function test_lucky_four_uses_its_own_stakes_and_released_prizes(): void
    {
        $this->fixtures();
        $this->reportAdmin('lucky-four-game');
        DB::table('ending_games')->insert(['id' => 2, 'team_a' => 1, 'team_b' => 2, 'is_lucky_four' => 'No', 'schedule' => '2026-10-16 08:00:00']);

        $this->get('/pagcor/reports/lucky-four-game?'.$this->params())->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('records.total', 1)->where('records.data.0.game_id', 1)
            ->where('records.data.0.gross_sales', fn ($v) => (float) $v === 25.0)
            ->where('records.data.0.payout', fn ($v) => (float) $v === 5.0)
            ->where('records.data.0.ggr', fn ($v) => (float) $v === 20.0)
            ->where('records.data.0.pagcor_share', fn ($v) => (float) $v === 5.0));
    }

    public static function periods(): array
    {
        return ['daily' => ['daily', '2026-10-16'], 'weekly' => ['weekly', '2026-10-12'], 'monthly' => ['monthly', '2026-10'], 'yearly' => ['yearly', '2026']];
    }

    #[DataProvider('periods')]
    public function test_progress_groups_all_games_by_philippine_schedule_date(string $period, string $label): void
    {
        $this->fixtures();
        $this->reportAdmin('ending-progress');
        DB::table('ending_games')->insert(['id' => 2, 'team_a' => 1, 'team_b' => 2, 'schedule' => '2026-10-16 15:59:59']);

        $this->get('/pagcor/reports/ending-progress?'.$this->params(['period' => $period]))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('records.total', 1)->where('records.data.0.period', $label)->where('records.data.0.games', 2)
            ->where('records.data.0.gross_sales', fn ($v) => (float) $v === 60.0)
            ->where('records.data.0.pagcor_share', fn ($v) => (float) $v === -22.5));
    }

    public function test_lucky_progress_and_no_activity_dates_keep_correct_game_counts(): void
    {
        $this->fixtures();
        $this->reportAdmin('lucky-four-progress');

        $this->get('/pagcor/reports/lucky-four-progress?'.$this->params(['date_to' => '2026-10-17']))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('records.total', 2)->where('records.data.0.games', 1)->where('records.data.1.games', 0)
            ->where('records.data.1.period', '2026-10-17')->where('records.data.1.pagcor_share', fn ($v) => (float) $v === 0.0)
            ->loadDeferredProps(fn (AssertableInertia $deferred) => $deferred->where('totals.games', 1)));
    }

    public function test_team_filter_cannot_escape_league_date_or_status_and_includes_either_team(): void
    {
        $this->fixtures();
        $this->reportAdmin('ending-game');
        DB::table('ending_teams')->insert(['id' => 3, 'league_id' => 2, 'team_name' => 'Other']);
        DB::table('ending_games')->insert([
            ['id' => 2, 'team_a' => 3, 'team_b' => 2, 'game_status_id' => 5, 'schedule' => '2026-10-16 08:00:00'],
            ['id' => 3, 'team_a' => 1, 'team_b' => 2, 'game_status_id' => 5, 'schedule' => '2026-10-16 16:00:00'],
            ['id' => 4, 'team_a' => 1, 'team_b' => 2, 'game_status_id' => null, 'schedule' => '2026-10-16 15:59:59'],
            ['id' => 5, 'team_a' => 1, 'team_b' => 2, 'game_status_id' => 5, 'schedule' => '2026-10-15 15:59:59'],
        ]);

        $this->get('/pagcor/reports/ending-game?'.$this->params(['team_id' => 2, 'league_id' => 1, 'status' => 'Finished']))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('records.total', 1)->where('records.data.0.game_id', 1));
    }

    public function test_transactional_report_excludes_token_purchases_and_withdrawals_and_preserves_outlets(): void
    {
        $this->fixtures();
        $this->reportAdmin('transactional');
        DB::table('player_wallet_transactions')->insert([
            ['id' => 3, 'player_id' => 9, 'transaction_type_id' => 2, 'description' => 'Withdraw', 'amount' => 999, 'created_at' => '2026-10-16 00:00:00'],
            ['id' => 4, 'player_id' => 9, 'transaction_type_id' => 3, 'description' => 'Next day', 'amount' => 999, 'created_at' => '2026-10-16 16:00:00'],
        ]);

        $this->get('/pagcor/reports/transactional?'.$this->params(['date_to' => '2026-10-17']))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('records.total', 2)
                ->where('records.data.0.transaction_id', 2)->where('records.data.0.transaction_type', 'Ending Bet')
                ->where('records.data.0.outlet', 'Test Outlet')->where('records.data.0.channel_type', 'RGP')
                ->where('records.data.1.transaction_id', 4));
    }

    #[DataProvider('reports')]
    public function test_no_activity_rows_are_exported_with_dates_and_a_clear_label(string $report): void
    {
        $this->fixtures();
        $this->reportAdmin($report);

        $response = $this->get('/pagcor/reports/'.$report.'/export?'.$this->params(['date_from' => '2026-10-17']))->assertOk();
        $this->assertStringContainsString('2026-10-17,"No Activity"', $response->streamedContent());
    }

    public function test_exports_keep_filters_sort_page_and_spreadsheet_escaping_and_create_real_pdf(): void
    {
        $this->fixtures();
        $this->reportAdmin('ending-game');
        DB::table('ending_leagues')->where('id', 1)->update(['league' => '=SUM(1,1)']);

        $csv = $this->get('/pagcor/reports/ending-game/export?'.$this->params())->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString("'=SUM(1,1)", $csv->streamedContent());
        $this->assertStringContainsString('2026-10-16 00:00:00', $csv->streamedContent());
        $pdf = $this->get('/pagcor/reports/ending-game/export?'.$this->params(['scope' => 'page', 'format' => 'pdf']))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', $pdf->getContent());
        $this->getJson('/pagcor/reports/ending-game/export?'.$this->params(['format' => 'pdf']))->assertUnprocessable()->assertJsonValidationErrors('scope');
    }

    public function test_totals_cover_all_matching_pages_and_search_is_applied_to_exports(): void
    {
        $this->fixtures();
        $this->reportAdmin('ending-game');
        foreach (range(2, 7) as $id) {
            DB::table('ending_games')->insert(['id' => $id, 'team_a' => 1, 'team_b' => 2, 'schedule' => '2026-10-16 08:00:00']);
        }

        $this->get('/pagcor/reports/ending-game?'.$this->params(['per_page' => 5, 'page' => 2]))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('records.total', 7)->has('records.data', 2)
                ->loadDeferredProps(fn (AssertableInertia $deferred) => $deferred->where('totals.gross_sales', fn ($v) => (float) $v === 60.0)));
        $csv = $this->get('/pagcor/reports/ending-game/export?'.$this->params(['per_page' => 5, 'page' => 2, 'scope' => 'page']))->assertOk();
        $this->assertCount(3, explode("\n", trim($csv->streamedContent())));
        $search = $this->get('/pagcor/reports/ending-game/export?'.$this->params(['search' => 'Finished']))->assertOk();
        $this->assertCount(2, explode("\n", trim($search->streamedContent())));
    }

    public static function invalidFilters(): array
    {
        return [
            'missing start' => [['date_from' => ''], 'date_from'],
            'invalid date' => [['date_from' => '2026-02-30'], 'date_from'],
            'reversed range' => [['date_to' => '2026-10-15'], 'date_to'],
            'too long' => [['date_to' => '2099-01-01'], 'date_to'],
            'sort injection' => [['sort' => 'id;DROP TABLE players'], 'sort'],
            'status' => [['status' => 'unknown'], 'status'],
            'period' => [['period' => 'unknown'], 'period'],
            'page size' => [['per_page' => 99999], 'per_page'],
            'negative page' => [['page' => -1], 'page'],
            'venue' => [['gaming_venue' => 'test_accounts'], 'gaming_venue'],
        ];
    }

    #[DataProvider('invalidFilters')]
    public function test_invalid_page_and_export_filters_return_422(array $filters, string $field): void
    {
        $this->reportAdmin('ending-game');

        $this->getJson('/pagcor/reports/ending-game?'.$this->params($filters))->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->getJson('/pagcor/reports/ending-game/export?'.$this->params($filters))->assertUnprocessable()->assertJsonValidationErrors($field);
    }

    public function test_missing_ggr_is_reported_instead_of_silently_using_the_general_report_rate(): void
    {
        $this->fixtures();
        $this->reportAdmin('ending-game');
        DB::table('ending_config')->where('config_name', 'GGR')->delete();

        $this->getJson('/pagcor/reports/ending-game?'.$this->params())->assertUnprocessable()->assertJsonValidationErrors('report');
    }

    public function test_progress_rounds_the_share_after_aggregating_the_period(): void
    {
        $this->fixtures();
        $this->reportAdmin('ending-progress');
        DB::table('ending_bet_amounts')->where('id', 1)->update(['amount' => '0.01']);
        DB::table('ending_game_winners')->update(['prize_released' => 'No']);

        $this->get('/pagcor/reports/ending-progress?'.$this->params())->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('records.data.0.pagcor_share', fn ($v) => (float) $v === 0.01));
    }

    public function test_game_dates_and_no_activity_rows_sort_together_chronologically(): void
    {
        $this->fixtures();
        $this->reportAdmin('ending-game');

        $this->get('/pagcor/reports/ending-game?'.$this->params(['date_from' => '2026-10-15', 'date_to' => '2026-10-17']))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('records.total', 3)
                ->where('records.data.0.day', '2026-10-15')->where('records.data.0.game_id', null)
                ->where('records.data.1.day', '2026-10-16')->where('records.data.1.game_id', 1)
                ->where('records.data.2.day', '2026-10-17')->where('records.data.2.game_id', null));
    }

    public function test_daily_calendar_handles_multi_year_ranges_without_missing_days(): void
    {
        $this->fixtures();
        $this->reportAdmin('ending-progress');

        $this->get('/pagcor/reports/ending-progress?'.$this->params(['date_from' => '2024-01-01', 'date_to' => '2026-12-31', 'per_page' => 100, 'page' => 11]))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('records.total', 1096)->has('records.data', 96)->where('records.data.95.period', '2026-12-31'));
    }

    private function reportAdmin(string $report): void
    {
        $user = $this->endingAdmin([]);
        $permission = PagcorReports::DEFINITIONS[$report][1];
        $user->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }

    private function params(array $overrides = []): string
    {
        return http_build_query(array_replace(['submitted' => 1, 'date_from' => '2026-10-16', 'gaming_venue' => 'lascasas_bagac_bataan'], $overrides));
    }

    private function fixtures(): void
    {
        $this->playerFixtures();
        config(['ending.reports.agent_connection' => 'sqlite']);
        Schema::table('player_wallet_transactions', fn (Blueprint $t) => $t->unsignedBigInteger('outlet_id')->nullable());
        DB::table('admin_outlets')->insert(['id' => 1, 'outlet_name' => 'Test Outlet']);
        DB::table('ending_config')->insert([
            ['id' => 6, 'config_name' => 'GGR', 'config_value' => '0.25'],
            ['id' => 19, 'config_name' => 'PAGCOR Percentage', 'config_value' => '22.5'],
        ]);
        DB::table('ending_leagues')->insert(['id' => 1, 'league' => 'Test League']);
        DB::table('ending_teams')->insert([['id' => 1, 'league_id' => 1, 'team_name' => 'Blue'], ['id' => 2, 'league_id' => 1, 'team_name' => 'Red']]);
        DB::table('ending_games')->insert(['id' => 1, 'team_a' => 1, 'team_b' => 2, 'game_status_id' => 5, 'schedule' => '2026-10-15 16:00:00', 'is_lucky_four' => 'Yes']);
        DB::table('ending_bet_types')->insert(['id' => 1, 'type' => 'Traditional']);
        DB::table('ending_bet_amounts')->insert(['id' => 1, 'game_id' => 1, 'bet_type_id' => 1, 'amount' => 20]);
        DB::table('ending_cards')->insert(['id' => 1, 'bet_amount_id' => 1]);
        foreach (range(1, 4) as $id) {
            DB::table('ending_bets')->insert(['id' => $id, 'card_id' => 1, 'slot_id' => 1, 'player_id' => 9, 'game_type_id' => $id === 4 ? 2 : 1]);
        }
        foreach ([[1, 'Cash', 100], [2, 'Cash', 50], [3, 'Other', 999]] as [$quarter, $kind, $cash]) {
            DB::table('ending_bet_amount_prizes')->insert(['bet_amount_id' => 1, 'quarter_id' => $quarter, 'is_cash' => $kind, 'cash_prize' => $cash]);
        }
        foreach ([[1, 1, 'Yes'], [1, 2, 'Yes'], [1, 3, 'Yes'], [2, 1, 'No']] as [$bet, $quarter, $released]) {
            DB::table('ending_game_winners')->insert(['bet_id' => $bet, 'quarter_id' => $quarter, 'prize_released' => $released]);
        }
        DB::table('lucky_four_bets')->insert([
            ['ending_bet_id' => 1, 'game_id' => 1, 'amount' => 10], ['ending_bet_id' => 2, 'game_id' => 1, 'amount' => 15],
        ]);
        DB::table('lucky_four_winners')->insert([
            ['ending_bet_id' => 1, 'prize_amount' => 5, 'prize_released' => 'Yes'], ['ending_bet_id' => 2, 'prize_amount' => 99, 'prize_released' => 'No'],
        ]);
        DB::table('player_wallet_transaction_types')->insert([['id' => 2, 'wallet_transaction_type' => 'Withdraw'], ['id' => 3, 'wallet_transaction_type' => 'Bet']]);
        DB::table('player_wallet_transactions')->insert(['id' => 2, 'player_id' => 9, 'transaction_type_id' => 3, 'outlet_id' => 1, 'description' => 'Ending bet', 'amount' => 20, 'created_at' => '2026-10-15 16:00:00']);
    }
}
