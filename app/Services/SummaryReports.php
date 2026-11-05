<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SummaryReports
{
    private const SOURCES = [
        'players' => ['created_at'],
        'ending_bets' => ['id', 'created_at', 'card_id'],
        'otso_panalo_bets' => ['created_at', 'amount'],
        'lucky_four_bets' => ['ending_bet_id', 'amount'],
        'ending_cards' => ['id', 'bet_amount_id'],
        'ending_bet_amounts' => ['id', 'amount'],
    ];

    /** @return list<array{year: int, total_players: int, bet_count: int, bet_amount: string}> */
    public function rows(): array
    {
        $state = DB::table('admin_summary_report_state')->where('id', 1)->first();
        if ($state->rebuilt_at === null || ($state->next_refresh_at !== null && $state->next_refresh_at <= now('UTC')->format('Y-m-d H:i:s'))) {
            $this->rebuild();
        }

        return array_values(DB::table('admin_summary_reports')
            ->where(fn (Builder $query) => $query->where('total_players', '<>', 0)->orWhere('bet_count', '<>', 0)->orWhere('bet_amount', '<>', 0))
            ->orderBy('year')->get(['year', 'total_players', 'bet_count', 'bet_amount'])
            ->map(fn (object $row): array => [
                'year' => (int) $row->year,
                'total_players' => (int) $row->total_players,
                'bet_count' => (int) $row->bet_count,
                'bet_amount' => number_format((float) $row->bet_amount, 2, '.', ''),
            ])->all());
    }

    public function rebuild(): void
    {
        DB::transaction(function (): void {
            // Source-write triggers take this same lock before changing any totals.
            DB::table('admin_summary_report_state')->where('id', 1)->lockForUpdate()->first();
            $now = now('UTC');
            $rows = $this->aggregate();
            DB::table('admin_summary_reports')->delete();
            foreach ($rows as $row) {
                DB::table('admin_summary_reports')->insert([...$row, 'updated_at' => $now]);
            }

            $futureDates = [];
            foreach (['players', 'ending_bets', 'otso_panalo_bets'] as $table) {
                if (Schema::hasTable($table)) {
                    $next = DB::table($table)->where('created_at', '>', $now)->min('created_at');
                    if (is_string($next)) {
                        $futureDates[] = $next;
                    }
                }
            }
            DB::table('admin_summary_report_state')->where('id', 1)->update([
                'rebuilt_at' => $now,
                'next_refresh_at' => $futureDates === [] ? null : min($futureDates),
            ]);
        }, 3);
    }

    public function installTriggers(): void
    {
        foreach (self::SOURCES as $table => $columns) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            foreach (['insert' => 'INSERT', 'update' => 'UPDATE', 'delete' => 'DELETE'] as $suffix => $event) {
                $future = in_array($table, ['players', 'ending_bets', 'otso_panalo_bets'], true) && $event !== 'DELETE' ? 'NEW.created_at' : 'NULL';
                $clock = $this->clock();
                $body = "UPDATE admin_summary_report_state SET revision = revision + 1,
                    next_refresh_at = CASE WHEN {$future} > {$clock}
                        AND (next_refresh_at IS NULL OR {$future} < next_refresh_at)
                        THEN {$future} ELSE next_refresh_at END WHERE id = 1; ";
                if ($event !== 'INSERT') {
                    $body .= $this->contribution($table, 'OLD', -1);
                }
                if ($event !== 'DELETE') {
                    $body .= $this->contribution($table, 'NEW', 1);
                }
                $when = '';
                if ($event === 'UPDATE') {
                    $changes = $this->changes($columns);
                    if (DB::getDriverName() === 'sqlite') {
                        $when = 'WHEN '.$changes;
                    } else {
                        $body = "IF {$changes} THEN {$body} END IF;";
                    }
                }
                DB::unprepared("CREATE TRIGGER IF NOT EXISTS admin_summary_{$table}_{$suffix}
                    AFTER {$event} ON {$table} FOR EACH ROW {$when} BEGIN {$body} END");
            }
        }
    }

    public function removeTriggers(): void
    {
        foreach (array_keys(self::SOURCES) as $table) {
            foreach (['insert', 'update', 'delete'] as $event) {
                DB::unprepared("DROP TRIGGER IF EXISTS admin_summary_{$table}_{$event}");
            }
        }
    }

    /** @return list<array{year: int, total_players: int, bet_count: int, bet_amount: string}> */
    private function aggregate(): array
    {
        $now = now('UTC');
        $from = CarbonImmutable::parse('2022-01-01', 'Asia/Manila')->utc();
        $playerYear = $this->year('created_at');
        $betYear = $this->year('b.created_at');
        $players = Schema::hasTable('players') ? DB::table('players')
            ->selectRaw($playerYear.' as year, COUNT(*) as total_players')
            ->where('created_at', '<=', $now)->groupByRaw($playerYear)->get()->keyBy('year') : collect();

        $cardCounts = DB::table('ending_bets')
            ->select('card_id')->selectRaw($playerYear.' as year, COUNT(*) as bet_count')
            ->where('created_at', '>=', $from)->where('created_at', '<=', $now)
            ->groupByRaw($playerYear)->groupBy('card_id');
        $cards = DB::table('ending_cards')->select('id', 'bet_amount_id');
        $bets = DB::query()->fromSub($cardCounts, 'bets')
            ->leftJoinSub($cards, 'c', 'c.id', '=', 'bets.card_id')
            ->leftJoin('ending_bet_amounts as a', 'a.id', '=', 'c.bet_amount_id')
            ->select('bets.year')->selectRaw('SUM(bets.bet_count) as bet_count, COALESCE(SUM(bets.bet_count * a.amount), 0) as bet_amount')
            ->groupBy('bets.year')->get()->keyBy('year');
        $luckyFour = DB::table('lucky_four_bets as l')->join('ending_bets as b', 'b.id', '=', 'l.ending_bet_id')
            ->selectRaw($betYear.' as year, COALESCE(SUM(l.amount), 0) as bet_amount')
            ->where('b.created_at', '>=', $from)->where('b.created_at', '<=', $now)
            ->groupByRaw($betYear)->get()->keyBy('year');
        $otso = DB::table('otso_panalo_bets')
            ->selectRaw($playerYear.' as year, COUNT(*) as bet_count, COALESCE(SUM(amount), 0) as bet_amount')
            ->where('created_at', '>=', $from)->where('created_at', '<=', $now)
            ->groupByRaw($playerYear)->get()->keyBy('year');

        return array_values($players->keys()->merge($bets->keys())->merge($otso->keys())->unique()->sort()
            ->map(fn (int $year): array => [
                'year' => $year,
                'total_players' => (int) ($players->get($year)->total_players ?? 0),
                'bet_count' => (int) ($bets->get($year)->bet_count ?? 0) + (int) ($otso->get($year)->bet_count ?? 0),
                'bet_amount' => number_format(
                    (float) ($bets->get($year)->bet_amount ?? 0) + (float) ($luckyFour->get($year)->bet_amount ?? 0)
                        + (float) ($otso->get($year)->bet_amount ?? 0),
                    2, '.', '',
                ),
            ])->all());
    }

    /**
     * @param  literal-string  $table
     * @param  literal-string  $row
     * @param  -1|1  $sign
     * @return literal-string
     */
    private function contribution(string $table, string $row, int $sign): string
    {
        if ($table === 'players') {
            $year = $this->year($row.'.created_at');
            $valid = $this->eligible($row.'.created_at', false);

            return $this->upsert("SELECT {$year} as year, {$sign} as total_players, 0 as bet_count, 0 as bet_amount
                FROM admin_summary_report_state WHERE id = 1 AND {$valid}");
        }
        if ($table === 'otso_panalo_bets') {
            $year = $this->year($row.'.created_at');
            $valid = $this->eligible($row.'.created_at');

            return $this->upsert("SELECT {$year} as year, 0 as total_players, {$sign} as bet_count,
                {$sign} * COALESCE({$row}.amount, 0) as bet_amount
                FROM admin_summary_report_state WHERE id = 1 AND {$valid}");
        }
        if ($table === 'ending_bets') {
            $year = $this->year($row.'.created_at');
            $valid = $this->eligible($row.'.created_at');

            return $this->upsert("SELECT {$year} as year, 0 as total_players, {$sign} as bet_count,
                {$sign} * (COALESCE((SELECT a.amount FROM ending_cards c
                    LEFT JOIN ending_bet_amounts a ON a.id = c.bet_amount_id WHERE c.id = {$row}.card_id), 0)
                    + COALESCE((SELECT amount FROM lucky_four_bets WHERE ending_bet_id = {$row}.id), 0)) as bet_amount
                FROM admin_summary_report_state WHERE id = 1 AND {$valid}");
        }

        $year = $this->year('b.created_at');
        $valid = $this->eligible('b.created_at');
        if ($table === 'lucky_four_bets') {
            return $this->upsert("SELECT {$year} as year, 0 as total_players, 0 as bet_count,
                {$sign} * COALESCE({$row}.amount, 0) as bet_amount
                FROM ending_bets b WHERE b.id = {$row}.ending_bet_id AND {$valid}");
        }
        if ($table === 'ending_cards') {
            return $this->upsert("SELECT {$year} as year, 0 as total_players, 0 as bet_count,
                SUM({$sign} * COALESCE((SELECT amount FROM ending_bet_amounts WHERE id = {$row}.bet_amount_id), 0)) as bet_amount
                FROM ending_bets b WHERE b.card_id = {$row}.id AND {$valid} GROUP BY {$year}");
        }

        return $this->upsert("SELECT {$year} as year, 0 as total_players, 0 as bet_count,
            SUM({$sign} * COALESCE({$row}.amount, 0)) as bet_amount FROM ending_bets b
            JOIN ending_cards c ON c.id = b.card_id WHERE c.bet_amount_id = {$row}.id AND {$valid} GROUP BY {$year}");
    }

    /**
     * @param  literal-string  $select
     * @return literal-string
     */
    private function upsert(string $select): string
    {
        $clock = $this->clock();
        $update = DB::getDriverName() === 'sqlite'
            ? 'ON CONFLICT(year) DO UPDATE SET
                total_players = total_players + excluded.total_players,
                bet_count = bet_count + excluded.bet_count, bet_amount = bet_amount + excluded.bet_amount,
                updated_at = excluded.updated_at'
            : 'ON DUPLICATE KEY UPDATE
                total_players = admin_summary_reports.total_players + delta.total_players,
                bet_count = admin_summary_reports.bet_count + delta.bet_count,
                bet_amount = admin_summary_reports.bet_amount + delta.bet_amount, updated_at = '.$clock;

        return "INSERT INTO admin_summary_reports (year, total_players, bet_count, bet_amount, updated_at)
            SELECT delta.year, delta.total_players, delta.bet_count, delta.bet_amount, {$clock}
            FROM ({$select}) as delta WHERE delta.year IS NOT NULL {$update}; ";
    }

    /**
     * @param  list<literal-string>  $columns
     * @return literal-string
     */
    private function changes(array $columns): string
    {
        $changes = [];
        foreach ($columns as $column) {
            $changes[] = DB::getDriverName() === 'sqlite'
                ? "OLD.{$column} IS NOT NEW.{$column}" : "NOT (OLD.{$column} <=> NEW.{$column})";
        }

        return implode(' OR ', $changes);
    }

    /**
     * @param  literal-string  $column
     * @return literal-string
     */
    private function eligible(string $column, bool $bet = true): string
    {
        return "{$column} IS NOT NULL AND {$column} <= ".$this->clock()
            .($bet ? " AND {$column} >= '2021-12-31 16:00:00'" : '');
    }

    /** @return literal-string */
    private function clock(): string
    {
        return DB::getDriverName() === 'sqlite' ? 'summary_report_now()' : 'UTC_TIMESTAMP()';
    }

    /**
     * @param  literal-string  $column
     * @return literal-string
     */
    private function year(string $column): string
    {
        return DB::getDriverName() === 'sqlite'
            ? "CAST(strftime('%Y', {$column}, '+8 hours') AS INTEGER)"
            : "YEAR(DATE_ADD({$column}, INTERVAL 8 HOUR))";
    }
}
