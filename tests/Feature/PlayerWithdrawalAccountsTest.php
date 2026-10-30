<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\EndingFixtures;
use Tests\Support\PlayerFixtures;
use Tests\TestCase;

class PlayerWithdrawalAccountsTest extends TestCase
{
    use EndingFixtures, PlayerFixtures, RefreshDatabase;

    public function test_withdrawal_accounts_require_an_authenticated_admin_with_player_list_permission(): void
    {
        $this->get('/players/withdrawal_accounts')->assertRedirect('/login');
        $this->endingAdmin([17]);

        $this->get('/players/withdrawal_accounts')->assertForbidden();
    }

    public function test_list_includes_all_statuses_with_five_newest_accounts_and_safe_target_player_links(): void
    {
        $this->withdrawalFixtures();
        $this->endingAdmin([1]);

        $this->get('/players/withdrawal_accounts')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->component('players/WithdrawalAccounts')->has('accounts.data', 5)->where('accounts.total', 7)
            ->where('accounts.per_page', 5)->where('accounts.data.0.id', 6)->where('accounts.data.1.id', 5)
            ->where('accounts.data.0.player_id', null)->where('accounts.data.1.player_id', null)
            ->where('accounts.data.0.bank', null)->where('accounts.data.2.player_id', 9)
            ->where('accounts.data.3.is_verified', 'Rejected')->where('accounts.data.4.is_verified', 'Verified')
            ->where('firstAccountDate', '2024-07-02')->where('filters.sort', 'created_at')->where('filters.direction', 'desc')
            ->missing('accounts.data.0.legacy_player_id')->missing('accounts.data.0.legacy_checksum')
            ->missing('accounts.data.0.password'));

        $this->get('/players/withdrawal_accounts?page=2')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('accounts.current_page', 2)->has('accounts.data', 2)->where('accounts.data.0.id', 1));
    }

    #[DataProvider('searches')]
    public function test_search_matches_outlet_account_number_name_and_status(string $search, int $id): void
    {
        $this->withdrawalFixtures();
        $this->endingAdmin([1]);

        $this->get('/players/withdrawal_accounts?'.http_build_query(['search' => $search]))
            ->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('accounts.total', 1)->where('accounts.data.0.id', $id));
    }

    public static function searches(): array
    {
        return [
            'outlet' => ['BDO', 2],
            'account number including leading zeroes' => ['000004', 4],
            'account name' => ['Charlie', 3],
            'review status' => ['Rejected', 3],
        ];
    }

    public function test_date_filter_includes_the_whole_manila_day_using_utc_boundaries(): void
    {
        $this->withdrawalFixtures();
        $this->endingAdmin([1]);

        $this->get('/players/withdrawal_accounts?date_from=2026-10-16&date_to=2026-10-16')
            ->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('accounts.total', 2)->where('accounts.data.0.id', 4)->where('accounts.data.1.id', 3)
            ->where('firstAccountDate', '2024-07-02'));
        $this->get('/players/withdrawal_accounts?date_to=2024-07-02')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('accounts.total', 1)->where('accounts.data.0.id', 1));
        $this->get('/players/withdrawal_accounts?date_from=2026-10-17')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('accounts.total', 2));
    }

    public function test_sort_and_page_size_are_applied_on_the_server_and_filters_survive_pagination(): void
    {
        $this->withdrawalFixtures();
        $this->endingAdmin([1]);

        $this->get('/players/withdrawal_accounts?search=Holder&sort=account_number&direction=asc&per_page=5&page=2')
            ->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('accounts.total', 6)->has('accounts.data', 1)->where('accounts.data.0.id', 7)
            ->where('accounts.prev_page_url', fn (string $url) => str_contains($url, 'search=Holder') && str_contains($url, 'sort=account_number')));
        $this->get('/players/withdrawal_accounts?per_page=15')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('accounts.per_page', 15)->has('accounts.data', 7));
    }

    public function test_empty_data_and_unmatched_search_return_an_empty_page(): void
    {
        $this->playerFixtures();
        $this->endingAdmin([1]);
        $this->travelTo(new \DateTimeImmutable('2026-10-30 16:30:00 UTC'));

        $this->get('/players/withdrawal_accounts')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->has('accounts.data', 0)->where('firstAccountDate', null)->where('today', '2026-10-31'));
        DB::table('admin_player_fund_accounts')->insert(['player_id' => 9, 'bank_id' => 1, 'account_number' => '0123456789']);
        $this->get('/players/withdrawal_accounts?search='.urlencode("' OR 1=1 --"))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('accounts.total', 0));
        $this->get('/players/withdrawal_accounts?search=0')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('accounts.total', 1));
    }

    #[DataProvider('invalidFilters')]
    public function test_invalid_filters_return_validation_errors(string $query, string $field): void
    {
        $this->endingAdmin([1]);

        $this->getJson('/players/withdrawal_accounts?'.$query)->assertUnprocessable()->assertJsonValidationErrors($field);
    }

    public static function invalidFilters(): array
    {
        return [
            'unknown sort column' => ['sort=password', 'sort'],
            'invalid direction' => ['direction=sideways', 'direction'],
            'unsupported page size' => ['per_page=10000', 'per_page'],
            'nonpositive page' => ['page=0', 'page'],
            'invalid date' => ['date_from=2026-02-30', 'date_from'],
            'reversed date range' => ['date_from=2026-10-17&date_to=2026-10-16', 'date_to'],
            'oversized search' => ['search='.str_repeat('a', 101), 'search'],
        ];
    }

    private function withdrawalFixtures(): void
    {
        $this->playerFixtures();
        DB::table('admin_player_banks')->insert(['id' => 2, 'bank' => 'BDO', 'type' => 'Bank', 'is_active' => 'Inactive']);
        $rows = [
            [1, 9, 1, 'Alpha Holder', 'Pending', 'Active', '2024-07-01 16:00:00'],
            [2, 10, 2, 'Bravo Holder', 'Verified', 'Inactive', '2026-10-15 15:59:59'],
            [3, 11, 1, 'Charlie Holder', 'Rejected', 'Active', '2026-10-15 16:00:00'],
            [4, 9, 1, null, 'Pending', 'Active', '2026-10-16 15:59:59'],
            [5, null, 1, 'Quarantined Holder', 'Pending', 'Active', '2026-10-16 16:00:00'],
            [6, 999, 999, 'Orphan Holder', 'Pending', 'Active', '2026-10-16 16:00:00'],
            [7, 9, 1, 'Undated Holder', 'Pending', 'Active', null],
        ];
        foreach ($rows as [$id, $playerId, $bankId, $name, $verified, $active, $createdAt]) {
            DB::table('admin_player_fund_accounts')->insert([
                'id' => $id, 'player_id' => $playerId, 'bank_id' => $bankId,
                'account_number' => str_pad((string) $id, 6, '0', STR_PAD_LEFT), 'account_name' => $name,
                'is_verified' => $verified, 'is_active' => $active, 'created_at' => $createdAt,
            ]);
        }
    }
}
