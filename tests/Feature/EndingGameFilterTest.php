<?php

namespace Tests\Feature;

use App\Models\EndingGame;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Support\EndingFixtures;
use Tests\TestCase;

class EndingGameFilterTest extends TestCase
{
    use EndingFixtures, RefreshDatabase;

    public function test_initial_list_contains_only_todays_schedules_in_the_philippines(): void
    {
        $this->endingFixtures();
        $this->endingAdmin([17]);
        $this->travelTo(now()->setDate(2026, 10, 28)->setTime(17, 0));
        EndingGame::factory()->count(4)->sequence(
            ['schedule' => '2026-10-28 15:59:59'],
            ['schedule' => '2026-10-28 16:00:00'],
            ['schedule' => '2026-10-29 15:59:59', 'game_status_id' => 5],
            ['schedule' => '2026-10-29 16:00:00'],
        )->create(['team_a' => 1, 'team_b' => 2, 'created_at' => '2026-10-01 00:00:00']);

        $this->get(route('ending.games.index'))->assertInertia(fn (AssertableInertia $page) => $page
            ->component('ending/Games')->where('filters.date_from', '2026-10-29')->where('filters.date_to', '2026-10-29')
            ->where('games.total', 2)->where('games.data.0.id', 3)->where('games.data.1.id', 2));
    }

    #[TestWith([null])]
    #[TestWith([''])]
    public function test_an_omitted_or_empty_end_date_filters_only_the_start_day(?string $end): void
    {
        $this->endingFixtures();
        $this->endingAdmin([17]);
        EndingGame::factory()->count(4)->sequence(
            ['schedule' => '2026-10-19 15:59:59'],
            ['schedule' => '2026-10-19 16:00:00'],
            ['schedule' => '2026-10-20 15:59:59'],
            ['schedule' => '2026-10-20 16:00:00'],
        )->create(['team_a' => 1, 'team_b' => 2]);

        $this->get(route('ending.games.index', ['date_from' => '2026-10-20', 'date_to' => $end]))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('filters.date_to', '2026-10-20')->where('games.total', 2)
                ->where('games.data.0.id', 3)->where('games.data.1.id', 2));
    }

    /** @param list<int> $ids */
    #[TestWith([null, [3, 2]])]
    #[TestWith([0, [3, 2]])]
    #[TestWith([1, [2]])]
    public function test_schedule_ranges_can_be_limited_to_a_league_or_include_all_leagues(?int $league, array $ids): void
    {
        $this->endingFixtures();
        $this->endingAdmin([17]);
        EndingGame::factory()->state(['team_a' => 1, 'team_b' => 2])->count(4)->sequence(
            ['schedule' => '2026-10-19 15:59:59'],
            ['schedule' => '2026-10-19 16:00:00'],
            ['schedule' => '2026-10-21 15:59:59', 'team_a' => 3],
            ['schedule' => '2026-10-21 16:00:00'],
        )->create();

        $this->get(route('ending.games.index', [
            'date_from' => '2026-10-20', 'date_to' => '2026-10-21', 'league_id' => $league,
        ]))->assertInertia(fn (AssertableInertia $page) => $page
            ->where('games.data', fn ($games) => $games->pluck('id')->all() === $ids));
    }

    public function test_pagination_keeps_the_applied_filters_and_the_normalized_end_date(): void
    {
        $this->endingFixtures();
        $this->endingAdmin([17]);
        EndingGame::factory()->count(6)->create(['team_a' => 1, 'team_b' => 2, 'schedule' => '2026-10-20 10:00:00']);

        $this->get(route('ending.games.index', [
            'date_from' => '2026-10-20', 'league_id' => 1, 'search' => 'Blue', 'per_page' => 5,
        ]))->assertInertia(fn (AssertableInertia $page) => $page
            ->where('games.total', 6)->has('games.data', 5)
            ->where('games.next_page_url', function (string $url): bool {
                parse_str(parse_url($url, PHP_URL_QUERY), $query);
                ksort($query);

                return $query === [
                    'date_from' => '2026-10-20', 'date_to' => '2026-10-20', 'league_id' => '1',
                    'page' => '2', 'per_page' => '5', 'search' => 'Blue',
                ];
            }));
    }

    /** @param array{date_from: string, date_to?: string} $filters */
    #[TestWith([['date_from' => ''], 'date_from'])]
    #[TestWith([['date_from' => 'invalid'], 'date_from'])]
    #[TestWith([['date_from' => '2026-10-20', 'date_to' => 'invalid'], 'date_to'])]
    #[TestWith([['date_from' => '2026-10-20', 'date_to' => '2026-10-19'], 'date_to'])]
    public function test_invalid_schedule_filters_return_validation_errors(array $filters, string $field): void
    {
        $this->endingFixtures();
        $this->endingAdmin([17]);

        $this->from(route('ending.games.index'))->get(route('ending.games.index', $filters))
            ->assertRedirect(route('ending.games.index'))->assertSessionHasErrors($field);
    }
}
