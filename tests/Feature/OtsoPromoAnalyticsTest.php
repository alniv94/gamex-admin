<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\OtsoPanaloFixtures;
use Tests\Support\OtsoPromoFixtures;
use Tests\TestCase;

class OtsoPromoAnalyticsTest extends TestCase
{
    use OtsoPanaloFixtures, OtsoPromoFixtures, RefreshDatabase;

    public function test_dashboard_requires_login_and_otso_panalo_permission(): void
    {
        $this->get(route('otso.analytics.index'))->assertRedirect(route('login'));
        $this->otsoAdmin([]);
        $this->get(route('otso.analytics.index'))->assertForbidden();
    }

    public function test_impressions_are_deduplicated_and_unknown_outcomes_are_not_closes(): void
    {
        $this->analyticsFixtures();
        $this->event('dialog_shown');
        $this->event('dialog_shown');
        $this->event('dialog_resumed');
        $this->event('play_clicked', ['duration_ms' => 2000]);
        $this->event('play_clicked', ['duration_ms' => 2000]);
        $this->event('game_page_viewed');
        $this->event('dialog_shown', ['impression_id' => 'second', 'dialog_step' => 2]);
        $this->event('dialog_closed', ['impression_id' => 'second', 'dialog_step' => 2, 'duration_ms' => 4000]);
        $this->event('dialog_shown', ['impression_id' => 'third', 'player_id' => 10]);
        $this->event('dialog_interrupted', ['impression_id' => 'third', 'player_id' => 10]);
        $this->event('dialog_shown', ['impression_id' => 'fourth', 'player_id' => 11]);
        $this->event('dialog_shown', ['impression_id' => 'other-campaign', 'campaign' => 'other']);

        $this->get($this->url())->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertInertia(fn (AssertableInertia $page) => $page->component('otso/PromoAnalytics')
                ->where('report.summary.impressions', 4)->where('report.summary.exposed_players', 3)
                ->where('report.summary.played', 1)->where('report.summary.clicking_players', 1)
                ->where('report.summary.ctr', 25)->where('report.summary.closed', 1)
                ->where('report.summary.interrupted', 1)->where('report.summary.unknown', 1)
                ->where('report.summary.average_foreground_ms', 3000)->where('report.summary.arrived', 1)
                ->has('report.steps', 2)->missing('report.events')->missing('report.players'));
        $this->assertDatabaseCount('otso_panalo_promo_events', 12);
    }

    public function test_manila_date_boundaries_and_actions_after_the_selected_day_are_handled(): void
    {
        $this->analyticsFixtures();
        $this->event('dialog_shown', ['occurred_at' => '2026-11-01 15:59:59', 'impression_id' => 'before']);
        $this->event('dialog_shown', ['occurred_at' => '2026-11-01 16:00:00', 'impression_id' => 'start']);
        $this->event('dialog_shown', ['occurred_at' => '2026-11-02 15:59:59', 'impression_id' => 'end']);
        $this->event('play_clicked', ['occurred_at' => '2026-11-02 16:00:00', 'impression_id' => 'end']);
        $this->event('dialog_shown', ['occurred_at' => '2026-11-02 16:00:00', 'impression_id' => 'next-day']);
        $this->event('dialog_closed', ['occurred_at' => '2026-11-03 16:00:00', 'impression_id' => 'start']);

        $this->get($this->url())->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('report.summary.impressions', 2)->where('report.summary.played', 1)->where('report.summary.closed', 0)
            ->where('report.daily.0.date', '2026-11-02')->where('report.daily.0.impressions', 2)->where('report.daily.0.played', 1)
            ->where('report.quality.events', 2)->where('report.attribution_pending', false));
    }

    public function test_bets_use_authoritative_records_cta_priority_and_source_bet_timezone(): void
    {
        $this->analyticsFixtures();
        $game = $this->otsoGame();
        $this->event('dialog_shown', ['occurred_at' => '2026-11-02 00:00:00']);
        $this->event('play_clicked', ['occurred_at' => '2026-11-02 00:05:00']);
        $this->event('dialog_shown', ['occurred_at' => '2026-11-02 00:10:00', 'impression_id' => 'second', 'dialog_step' => 2]);
        $this->event('dialog_shown', ['occurred_at' => '2026-11-02 00:00:00', 'player_id' => 10, 'impression_id' => 'new-player']);
        $this->event('bet_request_succeeded', ['player_id' => 11]);
        $this->event('bet_request_succeeded', ['player_id' => 11]);
        $this->otsoBet($game, 4, ['created_at' => '2026-10-01 08:00:00', 'amount' => '999.00']);
        $this->otsoBet($game, 4, ['created_at' => '2026-11-02 08:15:00', 'amount' => '20.10']);
        $this->otsoBet($game, 4, ['created_at' => '2026-11-02 08:16:00', 'amount' => '30.20', 'refunded' => 'Yes']);
        $this->otsoBet($game, 4, ['created_at' => '2026-11-03 07:59:00', 'amount' => '40.30', 'player_id' => 10]);
        $this->otsoBet($game, 4, ['created_at' => '2026-11-03 08:00:01', 'amount' => '700.00', 'player_id' => 10]);

        $this->get($this->url(['dialog_step' => 1]))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('report.conversion.bets', 3)->where('report.conversion.players', 2)
            ->where('report.conversion.first_time_bettors', 1)->where('report.conversion.gross_amount', 90.6)
            ->where('report.conversion.refunded_amount', 30.2));
        $this->get($this->url(['dialog_step' => 2]))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('report.conversion.bets', 0)->where('report.conversion.gross_amount', 0));
    }

    public function test_device_and_route_filters_do_not_reattribute_bets_from_a_later_click(): void
    {
        $this->analyticsFixtures();
        $this->event('dialog_shown', ['occurred_at' => '2026-11-02 00:00:00']);
        $this->event('play_clicked', ['occurred_at' => '2026-11-02 00:01:00']);
        $this->event('dialog_shown', ['occurred_at' => '2026-11-02 00:02:00', 'impression_id' => 'desktop', 'device_type' => 'desktop', 'source_route' => 'Games']);
        $this->event('play_clicked', ['occurred_at' => '2026-11-02 00:03:00', 'impression_id' => 'desktop', 'device_type' => 'desktop', 'source_route' => 'Games']);
        $this->otsoBet($this->otsoGame(), 4, ['created_at' => '2026-11-02 08:04:00']);

        $this->get($this->url(['device_type' => 'mobile', 'source_route' => 'Home']))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('report.summary.impressions', 1)
                ->where('report.conversion.bets', 0)->where('report.segments.0.device_type', 'mobile')->where('report.segments.0.source_route', 'Home'));
        $this->get($this->url(['device_type' => 'desktop', 'source_route' => 'Games']))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('report.conversion.bets', 1));
    }

    public function test_conversion_supports_utc_bet_timestamps_in_a_shared_player_database(): void
    {
        $this->analyticsFixtures();
        config(['otso.analytics.bet_timezone' => 'UTC']);
        $this->event('dialog_shown');
        $this->otsoBet($this->otsoGame(), 4, ['created_at' => '2026-11-02 00:30:00']);

        $this->get($this->url())->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('report.conversion.bets', 1)->where('report.conversion.first_time_bettors', 1));
    }

    public function test_engagement_remains_available_when_authoritative_bets_are_unavailable(): void
    {
        $this->analyticsFixtures();
        $this->event('dialog_shown');
        Schema::drop('otso_panalo_bets');

        $this->get($this->url())->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('report.summary.impressions', 1)->where('report.conversion', null));
    }

    public function test_tutorials_deduplicate_slide_revisits_and_require_explicit_completion(): void
    {
        $this->analyticsFixtures();
        $this->event('tutorial_opened', ['tutorial_id' => 'tutorial-a']);
        $this->event('tutorial_slide_viewed', ['tutorial_id' => 'tutorial-a', 'slide_index' => 1]);
        $this->event('tutorial_slide_viewed', ['tutorial_id' => 'tutorial-a', 'slide_index' => 1]);
        $this->event('tutorial_slide_viewed', ['tutorial_id' => 'tutorial-a', 'slide_index' => 5]);
        $this->event('tutorial_closed', ['tutorial_id' => 'tutorial-a', 'reason' => 'close_button']);
        $this->event('tutorial_opened', ['tutorial_id' => 'tutorial-b']);
        $this->event('tutorial_completed', ['tutorial_id' => 'tutorial-b', 'slide_index' => 5]);
        $this->event('tutorial_closed', ['tutorial_id' => 'tutorial-b', 'reason' => 'done']);
        $this->event('tutorial_opened', ['tutorial_id' => 'tutorial-c', 'source' => 'game_banner']);
        $this->event('tutorial_interrupted', ['tutorial_id' => 'tutorial-c', 'source' => 'game_banner']);

        $this->get($this->url())->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->has('report.tutorials', 2)->where('report.tutorials.0.source', 'game_banner')->where('report.tutorials.0.interrupted', 1)
            ->where('report.tutorials.1.opened', 2)->where('report.tutorials.1.completed', 1)->where('report.tutorials.1.dismissed', 1)
            ->where('report.tutorials.1.completion_rate', 50)->where('report.tutorials.1.slides', [1, 0, 0, 0, 1]));
    }

    public function test_retention_uses_first_ever_exposure_exact_return_days_and_mature_denominators(): void
    {
        $this->analyticsFixtures();
        $this->event('dialog_shown', ['occurred_at' => '2026-11-01 16:00:00']);
        $this->event('dialog_shown', ['occurred_at' => '2026-11-02 16:00:00', 'player_id' => 10, 'impression_id' => 'immature']);
        $this->event('dialog_shown', ['occurred_at' => '2026-10-01 16:00:00', 'player_id' => 11, 'impression_id' => 'older']);
        $this->event('dialog_shown', ['occurred_at' => '2026-11-01 16:00:00', 'player_id' => 11, 'impression_id' => 'reexposed']);
        $this->event('portal_visit', ['occurred_at' => '2026-11-08 16:00:00', 'device_type' => 'desktop']);
        $this->event('portal_visit', ['occurred_at' => '2026-11-09 15:59:59']);
        $this->event('game_page_viewed', ['occurred_at' => '2026-11-09 16:00:00']);
        $this->event('portal_visit', ['occurred_at' => '2026-11-09 16:00:00', 'player_id' => 10]);

        $this->get($this->url(['date_to' => '2026-11-03', 'device_type' => 'mobile']))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->has('report.retention', 6)
                ->where('report.retention.2.day', 7)->where('report.retention.2.cohort_date', '2026-11-02')
                ->where('report.retention.2.cohort_players', 1)->where('report.retention.2.eligible_players', 1)
                ->where('report.retention.2.portal_returned', 1)->where('report.retention.2.portal_rate', 100)
                ->where('report.retention.2.otso_returned', 0)->where('report.retention.2.otso_rate', 0)
                ->where('report.retention.3.eligible_players', 0)->where('report.retention.3.portal_rate', null)
                ->where('report.retention.4.eligible_players', 0)->where('report.retention.4.otso_rate', null));
    }

    public function test_diagnostics_report_failures_delivery_delay_and_client_clock_skew(): void
    {
        $this->analyticsFixtures();
        $this->event('bet_request_failed', ['http_status' => 422, 'reason' => 'http_error', 'received_at' => '2026-11-02 00:02:00']);
        $this->event('bet_blocked', ['reason' => 'insufficient_tokens', 'received_at' => '2026-11-02 00:00:00']);
        $this->event('navigation_failed', ['reason' => 'navigation', 'received_at' => '2026-11-01 23:59:59']);

        $this->get($this->url())->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->has('report.diagnostics', 3)->where('report.quality.events', 3)
            ->where('report.quality.delayed_events', 1)->where('report.quality.clock_skew_events', 1)
            ->where('report.quality.average_delay_seconds', 60));
    }

    public function test_no_data_has_null_rates_and_an_unavailable_table_has_a_separate_state(): void
    {
        $this->analyticsFixtures();

        $this->get($this->url())->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('report.summary.impressions', 0)->where('report.summary.ctr', null)
            ->where('report.summary.average_foreground_ms', null)->where('report.quality.events', 0)
            ->where('report.retention', [])->where('report.tutorials', [])->where('report.daily.0.played', 0));
        Schema::drop('otso_panalo_promo_events');
        $this->get($this->url())->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('report', null));
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function invalidFilters(): array
    {
        return [
            'date format' => [['date_from' => 'yesterday'], 'date_from'],
            'reversed dates' => [['date_from' => '2026-11-03'], 'date_to'],
            'range limit' => [['date_from' => '2026-01-01', 'date_to' => '2026-11-02'], 'date_to'],
            'future date' => [['date_to' => '2026-11-11'], 'date_to'],
            'unknown device' => [['device_type' => 'phone'], 'device_type'],
            'unknown step' => [['dialog_step' => 3], 'dialog_step'],
            'route injection' => [['source_route' => "Home' OR 1=1 --"], 'source_route'],
        ];
    }

    #[DataProvider('invalidFilters')]
    public function test_invalid_filters_are_rejected(array $filters, string $field): void
    {
        $this->analyticsFixtures();

        $this->get($this->url($filters))->assertSessionHasErrors($field);
    }

    public function test_analytics_source_connection_rejects_writes(): void
    {
        config(['database.connections.otso_analytics' => config('database.connections.sqlite')]);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('The otso_analytics database connection is read-only.');

        DB::connection('otso_analytics')->statement('CREATE TABLE unexpected_write (id INTEGER)');
    }

    private function analyticsFixtures(): void
    {
        $this->otsoFixtures();
        $this->otsoAdmin(['Otso Panalo']);
        $this->travelTo(CarbonImmutable::parse('2026-11-10 04:00:00', 'UTC'));
        config(['otso.analytics.connection' => 'sqlite', 'otso.analytics.bet_timezone' => 'Asia/Manila']);
        $this->promoEventsTable();
    }

    /** @param array<string, mixed> $filters */
    private function url(array $filters = []): string
    {
        return route('otso.analytics.index', $filters + ['date_from' => '2026-11-02', 'date_to' => '2026-11-02']);
    }
}
