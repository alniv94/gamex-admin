<?php

namespace App\Services\OtsoPanalo;

use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

class OtsoPromoAnalytics
{
    private const FLAGS = [
        'played' => 'play_clicked', 'closed' => 'dialog_closed',
        'interrupted' => 'dialog_interrupted', 'arrived' => 'game_page_viewed',
        'tutorial_opened' => 'tutorial_opened', 'submitted' => 'bet_submit_clicked',
    ];

    private function connection(): Connection
    {
        return DB::connection(config('otso.analytics.connection'));
    }

    private function events(string $alias = 'e'): Builder
    {
        return $this->connection()->table('otso_panalo_promo_events as '.$alias)
            ->where($alias.'.campaign', config('otso.analytics.campaign'));
    }

    /** @param array<string, mixed> $filters */
    private function filter(Builder $query, array $filters, string $alias = 'e'): Builder
    {
        foreach (['device_type', 'dialog_step', 'source_route'] as $field) {
            if (($filters[$field] ?? null) !== null) {
                $query->where($alias.'.'.$field, $filters[$field]);
            }
        }

        return $query;
    }

    /**
     * @param  literal-string  $expression
     * @param  literal-string  $hours
     * @return literal-string
     */
    private function shift(string $expression, string $hours): string
    {
        return $this->connection()->getDriverName() === 'sqlite'
            ? "datetime({$expression}, ({$hours}) || ' hours')"
            : "DATE_ADD({$expression}, INTERVAL ({$hours}) HOUR)";
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>|null
     */
    public function report(array $filters): ?array
    {
        if (! $this->connection()->getSchemaBuilder()->hasTable('otso_panalo_promo_events')) {
            return null;
        }
        $start = CarbonImmutable::parse($filters['date_from'], 'Asia/Manila')->utc();
        $end = CarbonImmutable::parse($filters['date_to'], 'Asia/Manila')->addDay()->utc();
        $now = CarbonImmutable::now('UTC');
        $exposures = $this->filter($this->events(), $filters)->where('e.event_name', 'dialog_shown')
            ->whereNotNull('e.impression_id')->where('e.occurred_at', '>=', $start)->where('e.occurred_at', '<', $end)
            ->where('e.occurred_at', '<=', $now)
            ->select('e.player_id', 'e.impression_id', 'e.dialog_step', 'e.source_route', 'e.device_type')
            ->selectRaw('MIN(e.occurred_at) as shown_at')
            ->groupBy('e.player_id', 'e.impression_id', 'e.dialog_step', 'e.source_route', 'e.device_type');
        $outcomes = $this->connection()->query()->fromSub($exposures, 'x')
            ->leftJoin('otso_panalo_promo_events as a', function (JoinClause $join) use ($now): void {
                $join->on('a.player_id', '=', 'x.player_id')->on('a.impression_id', '=', 'x.impression_id')
                    ->where('a.campaign', config('otso.analytics.campaign'))
                    ->on('a.occurred_at', '>=', 'x.shown_at')
                    ->whereRaw(DB::raw('a.occurred_at <= '.$this->shift('x.shown_at', '24')))->where('a.occurred_at', '<=', $now);
            })->select('x.player_id', 'x.impression_id', 'x.dialog_step', 'x.source_route', 'x.device_type', 'x.shown_at')
            ->selectRaw("MAX(CASE WHEN a.event_name IN ('play_clicked', 'dialog_closed') THEN a.duration_ms END) as foreground_ms")
            ->groupBy('x.player_id', 'x.impression_id', 'x.dialog_step', 'x.source_route', 'x.device_type', 'x.shown_at');
        foreach (self::FLAGS as $flag => $event) {
            $outcomes->selectRaw('MAX(CASE WHEN a.event_name = ? THEN 1 ELSE 0 END) as '.$flag, [$event]);
        }
        $summary = $this->engagement($outcomes)->first();
        $dailyDate = 'DATE('.$this->shift('o.shown_at', '8').')';
        $daily = $this->engagement($outcomes)->addSelect(DB::raw($dailyDate.' as date'))->groupBy(DB::raw($dailyDate))
            ->orderBy('date')->get()->keyBy('date');
        $trend = [];
        for ($date = $start->timezone('Asia/Manila'); $date->lt($end->timezone('Asia/Manila')); $date = $date->addDay()) {
            $key = $date->toDateString();
            $trend[] = ['date' => $key] + $this->metrics($daily->get($key));
        }
        $steps = $this->engagement($outcomes)->addSelect('o.dialog_step')->groupBy('o.dialog_step')
            ->orderBy('o.dialog_step')->get()->map(fn (object $row): array => ['dialog_step' => (int) $row->dialog_step] + $this->metrics($row))->all();
        $segments = $this->engagement($outcomes)->addSelect('o.dialog_step', 'o.device_type', 'o.source_route')
            ->groupBy('o.dialog_step', 'o.device_type', 'o.source_route')->orderBy('o.dialog_step')->orderBy('o.device_type')->orderBy('o.source_route')
            ->get()->map(fn (object $row): array => ['dialog_step' => (int) $row->dialog_step, 'device_type' => $row->device_type, 'source_route' => $row->source_route] + $this->metrics($row))->all();
        $window = $this->filter($this->events(), $filters)->where('e.occurred_at', '>=', $start)->where('e.occurred_at', '<', $end)->where('e.occurred_at', '<=', $now);
        $lag = $this->connection()->getDriverName() === 'sqlite'
            ? "(strftime('%s', e.received_at) - strftime('%s', e.occurred_at))"
            : 'TIMESTAMPDIFF(SECOND, e.occurred_at, e.received_at)';
        $quality = (clone $window)->selectRaw('COUNT(*) as events, MAX(e.received_at) as last_received_at, MAX(e.occurred_at) as last_occurred_at')
            ->selectRaw("AVG(CASE WHEN {$lag} >= 0 THEN {$lag} END) as average_delay_seconds, SUM(CASE WHEN {$lag} > 60 THEN 1 ELSE 0 END) as delayed_events, SUM(CASE WHEN {$lag} < 0 THEN 1 ELSE 0 END) as clock_skew_events")->first();
        $diagnostics = (clone $window)->whereIn('e.event_name', ['navigation_failed', 'game_list_failed', 'bet_request_failed', 'bet_blocked'])
            ->select('e.event_name', 'e.source', 'e.reason', 'e.http_status')->selectRaw('COUNT(*) as events, COUNT(DISTINCT e.player_id) as players')
            ->groupBy('e.event_name', 'e.source', 'e.reason', 'e.http_status')->orderByDesc('events')->get()
            ->map(fn (object $row): array => [...(array) $row, 'events' => (int) $row->events, 'players' => (int) $row->players])->all();

        return [
            'summary' => $this->metrics($summary), 'daily' => $trend, 'steps' => $steps, 'segments' => $segments,
            'conversion' => $this->conversion($exposures, $start, $end, $now),
            'tutorials' => $this->tutorials($window, $now),
            'retention' => $this->retention($exposures, $start, $end, $now),
            'diagnostics' => $diagnostics,
            'quality' => [
                'events' => (int) $quality->events, 'last_received_at' => $quality->last_received_at,
                'last_occurred_at' => $quality->last_occurred_at,
                'average_delay_seconds' => $quality->average_delay_seconds === null ? null : round((float) $quality->average_delay_seconds, 1),
                'delayed_events' => (int) $quality->delayed_events, 'clock_skew_events' => (int) $quality->clock_skew_events,
            ],
            'generated_at' => $now->toIso8601String(),
            'attribution_pending' => $end->addDay()->gt($now),
        ];
    }

    private function engagement(Builder $outcomes): Builder
    {
        $query = $this->connection()->query()->fromSub($outcomes, 'o')
            ->selectRaw('COUNT(*) as impressions, COUNT(DISTINCT o.player_id) as exposed_players, COUNT(DISTINCT CASE WHEN o.played = 1 THEN o.player_id END) as clicking_players')
            ->selectRaw('AVG(o.foreground_ms) as average_foreground_ms, SUM(CASE WHEN o.played = 0 AND o.closed = 0 AND o.interrupted = 0 THEN 1 ELSE 0 END) as unknown');
        foreach (array_keys(self::FLAGS) as $flag) {
            $query->selectRaw('SUM(o.'.$flag.') as '.$flag);
        }

        return $query;
    }

    /** @return array<string, int|float|null> */
    private function metrics(?object $row): array
    {
        $data = $row === null ? [] : get_object_vars($row);
        $values = [];
        foreach (['impressions', 'exposed_players', 'clicking_players', 'unknown', ...array_keys(self::FLAGS)] as $field) {
            $values[$field] = (int) ($data[$field] ?? 0);
        }

        return $values + [
            'ctr' => $this->rate($values['played'], $values['impressions']),
            'close_rate' => $this->rate($values['closed'], $values['impressions']),
            'frequency' => $values['exposed_players'] ? round($values['impressions'] / $values['exposed_players'], 2) : null,
            'average_foreground_ms' => ($row->average_foreground_ms ?? null) === null ? null : (int) round($row->average_foreground_ms),
        ];
    }

    private function rate(int $numerator, int $denominator): ?float
    {
        return $denominator ? round(100 * $numerator / $denominator, 2) : null;
    }

    /** @return array<string, int|float>|null */
    private function conversion(Builder $exposures, CarbonImmutable $start, CarbonImmutable $end, CarbonImmutable $now): ?array
    {
        if (! $this->connection()->getSchemaBuilder()->hasTable('otso_panalo_bets')) {
            return null;
        }
        $betTimezone = config('otso.analytics.bet_timezone');
        $betUtc = match ($betTimezone) {
            'UTC' => 'b.created_at',
            'Asia/Manila' => $this->shift('b.created_at', '-8'),
            default => throw new \InvalidArgumentException('Promo analytics supports UTC or Asia/Manila bet timestamps.'),
        };
        $candidate = $this->events('p')->whereColumn('p.player_id', 'b.player_id')->whereNotNull('p.impression_id')
            ->whereRaw(DB::raw('p.occurred_at <= '.$betUtc))->whereRaw(DB::raw('p.occurred_at >= '.$this->shift($betUtc, '-24')))
            ->orderByDesc('p.occurred_at')->orderByDesc('p.sequence')->orderByDesc('p.id')->limit(1)->select('p.impression_id');
        $click = (clone $candidate)->where('p.event_name', 'play_clicked');
        $shown = (clone $candidate)->where('p.event_name', 'dialog_shown');
        $bets = $this->connection()->table('otso_panalo_bets as b')
            ->where('b.created_at', '>=', $start->timezone($betTimezone)->format('Y-m-d H:i:s'))
            ->where('b.created_at', '<', $end->addDay()->timezone($betTimezone)->format('Y-m-d H:i:s'))
            ->where('b.created_at', '<=', $now->timezone($betTimezone)->format('Y-m-d H:i:s'))
            ->select('b.id', 'b.player_id', 'b.amount', 'b.refunded')
            ->selectSub($click, 'click_impression')->selectSub($shown, 'shown_impression')
            ->selectRaw('CASE WHEN NOT EXISTS (SELECT 1 FROM otso_panalo_bets prior WHERE prior.player_id = b.player_id AND (prior.created_at < b.created_at OR (prior.created_at = b.created_at AND prior.id < b.id))) THEN 1 ELSE 0 END as first_bet');
        $totals = $this->connection()->query()->fromSub($bets, 'b')->joinSub($exposures, 'x', function (JoinClause $join): void {
            $join->on('x.player_id', '=', 'b.player_id')->on('x.impression_id', '=', DB::raw('COALESCE(b.click_impression, b.shown_impression)'));
        })->selectRaw('COUNT(*) as bets, COUNT(DISTINCT b.player_id) as players, COUNT(DISTINCT CASE WHEN b.first_bet = 1 THEN b.player_id END) as first_time_bettors, COALESCE(SUM(b.amount), 0) as gross_amount')
            ->selectRaw("COALESCE(SUM(CASE WHEN b.refunded = 'Yes' THEN b.amount ELSE 0 END), 0) as refunded_amount")->first();

        return [
            'bets' => (int) $totals->bets, 'players' => (int) $totals->players, 'first_time_bettors' => (int) $totals->first_time_bettors,
            'gross_amount' => round((float) $totals->gross_amount, 2), 'refunded_amount' => round((float) $totals->refunded_amount, 2),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function tutorials(Builder $window, CarbonImmutable $now): array
    {
        $opens = (clone $window)->where('e.event_name', 'tutorial_opened')->whereNotNull('e.tutorial_id')
            ->select('e.player_id', 'e.tutorial_id', 'e.source')->selectRaw('MIN(e.occurred_at) as opened_at')
            ->groupBy('e.player_id', 'e.tutorial_id', 'e.source');
        $attempts = $this->connection()->query()->fromSub($opens, 't')->leftJoin('otso_panalo_promo_events as a', function (JoinClause $join) use ($now): void {
            $join->on('a.player_id', '=', 't.player_id')->on('a.tutorial_id', '=', 't.tutorial_id')
                ->where('a.campaign', config('otso.analytics.campaign'))->on('a.occurred_at', '>=', 't.opened_at')
                ->whereRaw(DB::raw('a.occurred_at <= '.$this->shift('t.opened_at', '24')))->where('a.occurred_at', '<=', $now);
        })->select('t.player_id', 't.tutorial_id', 't.source')->groupBy('t.player_id', 't.tutorial_id', 't.source')
            ->selectRaw("MAX(CASE WHEN a.event_name = 'tutorial_completed' THEN 1 ELSE 0 END) as completed")
            ->selectRaw("MAX(CASE WHEN a.event_name = 'tutorial_closed' AND (a.reason IS NULL OR a.reason <> 'done') THEN 1 ELSE 0 END) as dismissed")
            ->selectRaw("MAX(CASE WHEN a.event_name = 'tutorial_interrupted' THEN 1 ELSE 0 END) as interrupted");
        for ($slide = 1; $slide <= 5; $slide++) {
            $attempts->selectRaw("MAX(CASE WHEN a.event_name = 'tutorial_slide_viewed' AND a.slide_index = ? THEN 1 ELSE 0 END) as slide_{$slide}", [$slide]);
        }
        $totals = $this->connection()->query()->fromSub($attempts, 't')->select('t.source')->groupBy('t.source')->orderBy('t.source')
            ->selectRaw('COUNT(*) as opened, SUM(t.completed) as completed, SUM(t.dismissed) as dismissed, SUM(t.interrupted) as interrupted');
        for ($slide = 1; $slide <= 5; $slide++) {
            $totals->selectRaw("SUM(t.slide_{$slide}) as slide_{$slide}");
        }

        return array_values($totals->get()->map(function (object $row): array {
            $slides = [];
            for ($slide = 1; $slide <= 5; $slide++) {
                $slides[] = (int) $row->{'slide_'.$slide};
            }

            return ['source' => $row->source, 'opened' => (int) $row->opened, 'completed' => (int) $row->completed,
                'dismissed' => (int) $row->dismissed, 'interrupted' => (int) $row->interrupted,
                'completion_rate' => $this->rate((int) $row->completed, (int) $row->opened), 'slides' => $slides];
        })->all());
    }

    /** @return list<array<string, mixed>> */
    private function retention(Builder $exposures, CarbonImmutable $start, CarbonImmutable $end, CarbonImmutable $now): array
    {
        $first = $this->events()->where('e.event_name', 'dialog_shown')->whereNotNull('e.impression_id')
            ->select('e.player_id')->selectRaw('MIN(e.occurred_at) as first_at')->groupBy('e.player_id');
        $cohortDate = 'DATE('.$this->shift('f.first_at', '8').')';
        $cohorts = $this->connection()->query()->fromSub($first, 'f')->where('f.first_at', '>=', $start)->where('f.first_at', '<', $end)
            ->whereExists(function (Builder $query) use ($exposures): void {
                $query->fromSub($exposures, 'x')->selectRaw('1')->whereColumn('x.player_id', 'f.player_id')->whereColumn('x.shown_at', 'f.first_at');
            })->select('f.player_id')->addSelect(DB::raw($cohortDate.' as cohort_date'));
        $rows = [];
        foreach ([1, 7, 30] as $day) {
            $returnStart = $this->shift('c.cohort_date', 'c.day_number * 24 - 8');
            $returnEnd = $this->shift('c.cohort_date', '(c.day_number + 1) * 24 - 8');
            $cutoff = $now->timezone('Asia/Manila')->subDays($day)->toDateString();
            $result = $this->connection()->query()->fromSub((clone $cohorts)->selectRaw('? as day_number', [$day]), 'c')
                ->leftJoin('otso_panalo_promo_events as r', function (JoinClause $join) use ($returnStart, $returnEnd): void {
                    $join->on('r.player_id', '=', 'c.player_id')->where('r.campaign', config('otso.analytics.campaign'))
                        ->whereIn('r.event_name', ['portal_visit', 'game_page_viewed'])
                        ->whereRaw(DB::raw('r.occurred_at >= '.$returnStart))->whereRaw(DB::raw('r.occurred_at < '.$returnEnd));
                })->select('c.cohort_date')->groupBy('c.cohort_date')->orderBy('c.cohort_date')
                ->selectRaw('COUNT(DISTINCT c.player_id) as cohort_players')
                ->selectRaw('COUNT(DISTINCT CASE WHEN c.cohort_date < ? THEN c.player_id END) as eligible_players', [$cutoff])
                ->selectRaw("COUNT(DISTINCT CASE WHEN c.cohort_date < ? AND r.event_name = 'portal_visit' THEN c.player_id END) as portal_returned", [$cutoff])
                ->selectRaw("COUNT(DISTINCT CASE WHEN c.cohort_date < ? AND r.event_name = 'game_page_viewed' THEN c.player_id END) as otso_returned", [$cutoff]);
            foreach ($result->get() as $row) {
                $rows[] = ['cohort_date' => $row->cohort_date, 'day' => $day, 'cohort_players' => (int) $row->cohort_players,
                    'eligible_players' => (int) $row->eligible_players, 'portal_returned' => (int) $row->portal_returned, 'otso_returned' => (int) $row->otso_returned,
                    'portal_rate' => $this->rate((int) $row->portal_returned, (int) $row->eligible_players),
                    'otso_rate' => $this->rate((int) $row->otso_returned, (int) $row->eligible_players)];
            }
        }

        return $rows;
    }
}
