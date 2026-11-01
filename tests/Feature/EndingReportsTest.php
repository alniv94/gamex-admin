<?php

namespace Tests\Feature;

use App\Services\Ending\EndingReports;
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

class EndingReportsTest extends TestCase
{
    use EndingFixtures, PlayerFixtures, RefreshDatabase;

    public static function reports(): array
    {
        return array_combine(array_keys(EndingReports::DEFINITIONS), array_map(fn (string $report): array => [$report], array_keys(EndingReports::DEFINITIONS)));
    }

    #[DataProvider('reports')]
    public function test_each_report_and_export_enforces_its_own_permission(string $report): void
    {
        $url = '/ending/reports/'.$report;
        $this->get($url)->assertRedirect('/login');
        $this->get($url.'/export')->assertRedirect('/login');
        $this->endingAdmin([17]);

        $this->get($url)->assertForbidden();
        $this->get($url.'/export')->assertForbidden();
    }

    #[DataProvider('reports')]
    public function test_each_report_renders_paginated_rows_and_exports_the_same_columns(string $report): void
    {
        $this->reportFixtures();
        $this->reportAdmin($report);
        $params = '?date_from=2026-10-16&date_to=2026-10-16';
        $this->get('/ending/reports/'.$report.$params)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->component('ending/reports/Report')->where('report', $report)->where('records.per_page', 25)
            ->has('records.data')->has('endingReportNavigation', 11)->missing('records.data.0.password'));
        $response = $this->get('/ending/reports/'.$report.'/export'.$params)->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $rows = $this->csvRows($response->streamedContent());
        $this->assertSame(array_column(EndingReports::columns($report), 'title'), $rows[0]);
        $this->assertGreaterThan(1, count($rows));
    }

    public function test_game_financials_and_deferred_totals_do_not_multiply_bets_or_prizes(): void
    {
        $this->reportFixtures();
        $this->reportAdmin('game');

        $this->get('/ending/reports/game?date_from=2026-10-16&date_to=2026-10-16')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('records.total', 1)
                ->where('records.data.0.gross_sales', fn ($value) => (float) $value === 60.0)
                ->where('records.data.0.payout', fn ($value) => (float) $value === 150.0)
                ->where('records.data.0.ggr', fn ($value) => (float) $value === -90.0)
                ->where('records.data.0.pagcor_share', fn ($value) => (float) $value === -20.25)
                ->where('records.data.0.net_income', fn ($value) => (float) $value === -69.75)
                ->loadDeferredProps(fn (AssertableInertia $deferred) => $deferred
                    ->where('totals.gross_sales', fn ($value) => (float) $value === 60.0)
                    ->where('totals.payout', fn ($value) => (float) $value === 150.0)));
    }

    public function test_combinations_count_each_winning_bet_amount_and_released_prize_once(): void
    {
        $this->reportFixtures();
        $this->reportAdmin('top-winning-combination');

        $this->get('/ending/reports/top-winning-combination?date_from=2026-10-16&date_to=2026-10-16')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('records.total', 1)
                ->where('records.data.0.winners', fn ($value) => (int) $value === 4)
                ->where('records.data.0.bets', 2)
                ->where('records.data.0.gross_sales', fn ($value) => (float) $value === 40.0)
                ->where('records.data.0.payout', fn ($value) => (float) $value === 150.0));
    }

    public static function periods(): array
    {
        return ['daily' => ['daily', '2026-10-16'], 'weekly' => ['weekly', '2026-10-12'],
            'monthly' => ['monthly', '2026-10'], 'yearly' => ['yearly', '2026']];
    }

    #[DataProvider('periods')]
    public function test_progress_groups_philippine_dates_and_separates_bet_and_payout_activity(string $period, string $label): void
    {
        $this->reportFixtures();
        $this->reportAdmin('progress');
        DB::table('ending_games')->where('id', 1)->update(['schedule' => '2026-11-01 00:00:00']);

        $this->get('/ending/reports/progress?date_from=2026-10-16&date_to=2026-10-16&filter_by=bet&period='.$period)->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('records.total', 1)
                ->where('records.data.0.period', $label)->where('records.data.0.games', 1)->where('records.data.0.bettors', 1)
                ->where('records.data.0.bets', fn ($value) => (int) $value === 2)
                ->where('records.data.0.gross_sales', fn ($value) => (float) $value === 40.0)
                ->where('records.data.0.payout', fn ($value) => (float) $value === 100.0));
    }

    public function test_bet_date_boundaries_and_team_filter_cannot_escape_the_date_or_league(): void
    {
        $this->reportFixtures();
        $this->reportAdmin('bets');
        $this->get('/ending/reports/bets?date_from=2026-10-16&date_to=2026-10-16')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('records.total', 2)
                ->where('records.data.0.id', 2)->where('records.data.1.id', 1));
        $this->get('/ending/reports/bets?date_from=2026-10-17&date_to=2026-10-17&league_id=99&team_id=2')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('records.total', 0));
    }

    public function test_cashier_totals_use_request_status_and_date_and_keep_unmapped_history(): void
    {
        $this->reportFixtures();
        $this->reportAdmin('cash-in');
        DB::table('player_cashin')->insert([
            ['player_id' => 9, 'legacy_player_id' => null, 'amount' => 999, 'status' => 'Pending', 'created_at' => '2026-10-16 00:00:00'],
            ['player_id' => null, 'legacy_player_id' => 73, 'amount' => 15, 'status' => 'Approved', 'created_at' => '2026-10-16 00:00:00'],
        ]);

        $this->get('/ending/reports/cash-in?date_from=2026-10-16&date_to=2026-10-16&sort=id&direction=desc')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('records.total', 2)
                ->where('records.data.0.amount', fn ($value) => (float) $value === 50.0)
                ->where('records.data.1.id', -73)->where('records.data.1.amount', fn ($value) => (float) $value === 15.0));
    }

    public function test_exports_preserve_filters_pagination_dates_and_spreadsheet_safety(): void
    {
        $this->reportFixtures();
        $this->reportAdmin('bets');
        DB::table('ending_bets')->where('id', 1)->update(['name' => '=HYPERLINK("example")']);
        foreach (range(10, 21) as $id) {
            DB::table('ending_bets')->insert(['id' => $id, 'card_id' => 1, 'slot_id' => 1, 'player_id' => 9, 'created_at' => '2026-10-16 00:00:00']);
        }
        $params = ['date_from' => '2026-10-16', 'date_to' => '2026-10-16', 'sort' => 'id', 'direction' => 'asc', 'per_page' => 10, 'page' => 2];
        $this->get('/ending/reports/bets?'.http_build_query($params))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('records.total', 14)->has('records.data', 4)->where('records.data.0.id', 18));
        $current = $this->get('/ending/reports/bets/export?'.http_build_query($params + ['scope' => 'page']))->assertOk();
        $this->assertCount(5, $this->csvRows($current->streamedContent()));
        $all = $this->get('/ending/reports/bets/export?'.http_build_query($params))->assertOk();
        $rows = $this->csvRows($all->streamedContent());
        $this->assertCount(15, $rows);
        $this->assertSame("'=HYPERLINK(\"example\")", $rows[1][2]);
        $this->assertSame("'+639123456709", $rows[1][3]);
        $this->assertSame('2026-10-16 00:00:00', $rows[1][14]);
    }

    public function test_export_crosses_chunk_boundary_with_tied_and_null_sort_values_without_duplicates(): void
    {
        $this->reportFixtures();
        $this->reportAdmin('transactional');
        $rows = [];
        foreach (range(100, 2104) as $id) {
            $rows[] = ['id' => $id, 'player_id' => $id < 1100 ? 9 : 999, 'transaction_type_id' => 2,
                'description' => 'Chunk boundary', 'amount' => 1, 'created_at' => '2026-10-16 00:00:00'];
        }
        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table('player_wallet_transactions')->insert($chunk);
        }
        $response = $this->get('/ending/reports/transactional/export?date_from=2026-10-16&date_to=2026-10-16&sort=name&direction=desc')->assertOk();
        $rows = $this->csvRows($response->streamedContent());
        $ids = array_column(array_slice($rows, 1), 0);
        $this->assertCount(2006, $ids);
        $this->assertCount(2006, array_unique($ids));
    }

    public function test_pdf_is_a_bounded_real_document_and_rejects_full_history_rendering(): void
    {
        $this->reportFixtures();
        $this->reportAdmin('game');
        $response = $this->get('/ending/reports/game/export?date_from=2026-10-16&date_to=2026-10-16&format=pdf&scope=page')->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', $response->getContent());
        $this->getJson('/ending/reports/game/export?format=pdf&scope=all')->assertUnprocessable()->assertJsonValidationErrors('scope');
    }

    public static function invalidFilters(): array
    {
        return [
            'date' => [['date_from' => '2026-02-30'], 'date_from'],
            'range' => [['date_from' => '2026-10-17', 'date_to' => '2026-10-16'], 'date_to'],
            'sort injection' => [['sort' => 'id;DROP TABLE players'], 'sort'],
            'direction' => [['direction' => 'invalid'], 'direction'],
            'page size' => [['per_page' => 10000], 'per_page'],
            'page zero' => [['page' => 0], 'page'],
            'status' => [['status' => 'Invalid'], 'status'],
            'date basis' => [['filter_by' => 'password'], 'filter_by'],
            'period' => [['period' => 'invalid'], 'period'],
            'format' => [['format' => 'xlsx'], 'format'],
            'scope' => [['scope' => 'invalid'], 'scope'],
            'search length' => [['search' => str_repeat('a', 101)], 'search'],
        ];
    }

    #[DataProvider('invalidFilters')]
    public function test_invalid_filters_are_rejected_for_pages_and_exports(array $filters, string $field): void
    {
        $this->reportAdmin('bets');
        $this->getJson('/ending/reports/bets?'.http_build_query($filters))->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->getJson('/ending/reports/bets/export?'.http_build_query($filters))->assertUnprocessable()->assertJsonValidationErrors($field);
    }

    private function reportAdmin(string $report): void
    {
        $user = $this->endingAdmin([]);
        $user->givePermissionTo(Permission::findOrCreate(EndingReports::DEFINITIONS[$report][1], 'web'));
    }

    private function reportFixtures(): void
    {
        $this->playerFixtures();
        config(['ending.reports.agent_connection' => 'sqlite']);
        DB::table('ending_config')->insert(['id' => 19, 'config_name' => 'PAGCOR Percentage', 'config_value' => '22.5']);
        DB::table('ending_leagues')->insert(['id' => 1, 'league' => 'Test League']);
        DB::table('ending_teams')->insert([['id' => 1, 'league_id' => 1, 'team_name' => 'Blue'], ['id' => 2, 'league_id' => 1, 'team_name' => 'Red']]);
        DB::table('ending_games')->insert(['id' => 1, 'team_a' => 1, 'team_b' => 2, 'game_status_id' => 5, 'schedule' => '2026-10-15 16:00:00']);
        DB::table('ending_bet_types')->insert([['id' => 1, 'type' => 'Traditional'], ['id' => 2, 'type' => 'Per Quarter']]);
        DB::table('ending_bet_amounts')->insert(['id' => 1, 'game_id' => 1, 'bet_type_id' => 1, 'amount' => 20]);
        DB::table('ending_cards')->insert(['id' => 1, 'bet_amount_id' => 1, 'created_at' => '2026-10-15 16:00:00']);
        DB::table('ending_slots')->insert(['id' => 1, 'slot' => '0-0']);
        foreach ([[1, '2026-10-15 16:00:00', 1], [2, '2026-10-16 15:59:59', 1], [3, '2026-10-16 16:00:00', 1], [4, '2026-10-16 08:00:00', 2]] as [$id, $date, $gameType]) {
            DB::table('ending_bets')->insert(['id' => $id, 'card_id' => 1, 'player_id' => 9, 'slot_id' => 1, 'game_type_id' => $gameType, 'created_at' => $date]);
        }
        foreach ([[1, 'Cash', 100], [2, 'Cash', 50], [3, 'Other', 999]] as [$quarter, $kind, $cash]) {
            DB::table('ending_bet_amount_prizes')->insert(['bet_amount_id' => 1, 'quarter_id' => $quarter, 'is_cash' => $kind, 'cash_prize' => $cash]);
        }
        foreach ([[1, 1, 1, 'Yes', '2026-10-15 16:00:00'], [2, 1, 2, 'Yes', '2026-10-16 16:00:00'],
            [3, 1, 3, 'Yes', '2026-10-16 00:00:00'], [4, 2, 1, 'No', '2026-10-16 00:00:00']] as [$id, $bet, $quarter, $released, $date]) {
            DB::table('ending_game_winners')->insert(['id' => $id, 'bet_id' => $bet, 'quarter_id' => $quarter, 'prize_released' => $released, 'created_at' => $date]);
        }
        foreach (['player_cashin', 'player_cashout'] as $table) {
            DB::table($table)->insert(['player_id' => 9, 'amount' => 50, 'status' => 'Approved', 'created_at' => '2026-10-15 16:00:00']);
        }
        DB::table('player_wallet_transaction_types')->insert(['id' => 2, 'wallet_transaction_type' => 'Bet']);
        DB::table('player_wallet_transactions')->insert(['id' => 2, 'player_id' => 9, 'transaction_type_id' => 2, 'description' => 'Ending bet', 'amount' => 20, 'created_at' => '2026-10-15 16:00:00']);
        Schema::create('ending_agents', function (Blueprint $table): void {
            $table->id();
            $table->decimal('wallet', 10, 2);
        });
        Schema::create('ending_agent_info', function (Blueprint $table): void {
            $table->unsignedBigInteger('agent_id')->primary();
            $table->string('first_name');
            $table->string('last_name');
        });
        Schema::create('ending_agent_players', function (Blueprint $table): void {
            $table->unsignedBigInteger('agent_id');
            $table->unsignedBigInteger('player_id')->primary();
        });
        DB::table('ending_agents')->insert(['id' => 1, 'wallet' => 123]);
        DB::table('ending_agent_info')->insert(['agent_id' => 1, 'first_name' => 'Master', 'last_name' => 'Agent']);
        DB::table('ending_agent_players')->insert(['agent_id' => 1, 'player_id' => 9]);
    }

    private function csvRows(string $csv): array
    {
        $stream = fopen('php://temp', 'w+');
        fwrite($stream, substr($csv, 3));
        rewind($stream);
        $rows = [];
        while (($row = fgetcsv($stream, escape: '')) !== false) {
            $rows[] = $row;
        }
        fclose($stream);

        return $rows;
    }
}
