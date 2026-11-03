<?php

namespace App\Services\Pagcor;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PagcorReportQueries
{
    /** @param array<string, mixed> $filters */
    public function filtered(string $report, array $filters): Builder
    {
        if (! $filters['submitted']) {
            $query = DB::query()->selectRaw('NULL as id, NULL as day')->whereRaw('1 = 0');
            foreach (PagcorReports::columns($report) as $column) {
                $query->selectRaw('NULL as '.$column['key']);
            }

            return $query;
        }
        $query = $report === 'transactional' ? $this->transactions($filters) : $this->gameRows($report, $filters);
        if (str_ends_with($report, 'progress')) {
            $period = $this->period($filters['period']);
            $query = DB::query()->fromSub($query, 'days')->selectRaw($period.' as id, '.$period.' as period, MIN(day) as day')
                ->selectRaw('COUNT(game_id) as games, SUM(gross_sales) as gross_sales, SUM(payout) as payout, SUM(ggr) as ggr')
                ->selectRaw('ROUND(SUM(ggr) * ?, 2) as pagcor_share', [$this->rate()])
                ->groupByRaw($period);
        }
        $outer = DB::query()->fromSub($query, 'report');
        if (! empty($filters['search'])) {
            $outer->where(function (Builder $q) use ($report, $filters): void {
                foreach (PagcorReports::columns($report) as $column) {
                    $q->orWhere($column['key'], 'like', '%'.$filters['search'].'%');
                }
                $q->orWhere('day', 'like', '%'.$filters['search'].'%');
            });
        }

        if (in_array($filters['sort'], ['schedule', 'date_time', 'time_of_bet', 'settle_time'], true)) {
            $outer->orderBy('day', $filters['direction']);
        }

        return $outer->orderBy($filters['sort'], $filters['direction'])->orderBy('day', $filters['direction'])->orderBy('id', $filters['direction']);
    }

    /** @param array<string, mixed> $filters */
    private function calendar(array $filters): Builder
    {
        $days = (int) CarbonImmutable::parse($filters['date_from'])->diffInDays(CarbonImmutable::parse($filters['date_to']));
        $digits = DB::query()->selectRaw('0 as n');
        foreach (range(1, 9) as $digit) {
            $digits->unionAll(DB::query()->selectRaw('? as n', [$digit]));
        }
        $query = DB::query()->fromSub($digits, 'd0');
        $offset = 'd0.n';
        foreach ([1 => 10, 2 => 100, 3 => 1000] as $index => $factor) {
            if ($days >= $factor) {
                $query->crossJoinSub($digits, 'd'.$index);
                $offset .= ' + d'.$index.'.n * '.$factor;
            }
        }
        $date = DB::connection()->getDriverName() === 'sqlite'
            ? "date(?, '+' || ($offset) || ' days')" : "DATE_ADD(?, INTERVAL ($offset) DAY)";

        return $query->selectRaw($date.' as day', [$filters['date_from']])->whereRaw('('.$offset.') <= ?', [$days]);
    }

    /** @param literal-string $column
     * @return literal-string
     */
    private function localDate(string $column): string
    {
        return DB::connection()->getDriverName() === 'sqlite' ? "date($column, '+8 hours')" : "DATE(DATE_ADD($column, INTERVAL 8 HOUR))";
    }

    /** @return literal-string */
    private function period(string $period): string
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            return match ($period) {
                'weekly' => "date(day, '-6 days', 'weekday 1')",
                'monthly' => "strftime('%Y-%m', day)",
                'yearly' => "strftime('%Y', day)",
                default => 'day',
            };
        }

        return match ($period) {
            'weekly' => "DATE_FORMAT(DATE_SUB(day, INTERVAL WEEKDAY(day) DAY), '%Y-%m-%d')",
            'monthly' => "DATE_FORMAT(day, '%Y-%m')",
            'yearly' => "DATE_FORMAT(day, '%Y')",
            default => 'day',
        };
    }

    /** @param array<string, mixed> $filters
     * @param  literal-string  $column
     */
    private function dates(Builder $query, array $filters, string $column): void
    {
        $query->where($column, '>=', CarbonImmutable::parse($filters['date_from'], 'Asia/Manila')->startOfDay()->utc())
            ->where($column, '<', CarbonImmutable::parse($filters['date_to'], 'Asia/Manila')->addDay()->startOfDay()->utc());
    }

    private function rate(): string
    {
        $value = DB::table('ending_config')->where('config_name', 'GGR')->value('config_value')
            ?? DB::connection(config('ending.reports.agent_connection', 'legacy'))->table('ending_config')->where('config_name', 'GGR')->value('config_value');
        if (! is_numeric($value) || (float) $value < 0 || (float) $value > 1) {
            throw ValidationException::withMessages(['report' => 'Configure the GGR rate between 0 and 1 before generating PAGCOR reports.']);
        }

        return (string) $value;
    }

    /** @param array<string, mixed> $filters */
    private function gameRows(string $report, array $filters): Builder
    {
        $lucky = str_starts_with($report, 'lucky-four');
        $scope = $this->gameScope($filters, $lucky);
        $gameIds = (clone $scope)->select('g.id');
        if ($lucky) {
            $bets = DB::table('lucky_four_bets')->whereIn('game_id', $gameIds)->select('game_id')->selectRaw('SUM(amount) as gross_sales')->groupBy('game_id');
            $paid = DB::table('lucky_four_bets as b')->join('lucky_four_winners as w', 'w.ending_bet_id', '=', 'b.ending_bet_id')
                ->whereIn('b.game_id', $gameIds)->where('w.prize_released', 'Yes')->select('b.game_id')->selectRaw('SUM(w.prize_amount) as payout')->groupBy('b.game_id');
        } else {
            $bets = DB::table('ending_bets as b')->join('ending_cards as c', 'c.id', '=', 'b.card_id')->join('ending_bet_amounts as a', 'a.id', '=', 'c.bet_amount_id')
                ->whereIn('a.game_id', $gameIds)->where('b.game_type_id', 1)->select('a.game_id')->selectRaw('SUM(a.amount) as gross_sales')->groupBy('a.game_id');
            $prizes = DB::table('ending_bet_amount_prizes')->where('is_cash', 'Cash')->select('bet_amount_id', 'quarter_id')
                ->whereIn('bet_amount_id', DB::table('ending_bet_amounts')->whereIn('game_id', $gameIds)->select('id'))
                ->selectRaw('SUM(cash_prize) as cash_prize')->groupBy('bet_amount_id', 'quarter_id');
            $paid = DB::table('ending_game_winners as w')->join('ending_bets as b', 'b.id', '=', 'w.bet_id')
                ->join('ending_cards as c', 'c.id', '=', 'b.card_id')->join('ending_bet_amounts as a', 'a.id', '=', 'c.bet_amount_id')
                ->joinSub($prizes, 'prize', fn (JoinClause $join) => $join->on('prize.bet_amount_id', '=', 'a.id')->on('prize.quarter_id', '=', 'w.quarter_id'))
                ->whereIn('a.game_id', $gameIds)->where('b.game_type_id', 1)->where('w.prize_released', 'Yes')->select('a.game_id')->selectRaw('SUM(prize.cash_prize) as payout')->groupBy('a.game_id');
        }
        $games = $scope
            ->leftJoinSub($bets, 'bets', 'bets.game_id', '=', 'g.id')->leftJoinSub($paid, 'paid', 'paid.game_id', '=', 'g.id')
            ->select('g.id as game_id', 'l.league', 'g.schedule')->selectRaw($this->localDate('g.schedule').' as day')
            ->selectRaw("CASE WHEN COALESCE(g.game_status_id, 0) = 0 THEN 'Not Started' WHEN g.game_status_id = 5 THEN 'Finished' ELSE 'Ongoing' END as game_status")
            ->selectRaw('COALESCE(bets.gross_sales, 0) as gross_sales, COALESCE(paid.payout, 0) as payout');
        $name = DB::connection()->getDriverName() === 'sqlite'
            ? "TRIM(COALESCE(ta.team_name, '') || ' ' || COALESCE(ta.team_alias, '')) || ' vs ' || TRIM(COALESCE(tb.team_name, '') || ' ' || COALESCE(tb.team_alias, ''))"
            : "CONCAT(TRIM(CONCAT(COALESCE(ta.team_name, ''), ' ', COALESCE(ta.team_alias, ''))), ' vs ', TRIM(CONCAT(COALESCE(tb.team_name, ''), ' ', COALESCE(tb.team_alias, ''))))";
        $games->selectRaw($name.' as game');
        $rows = DB::query()->fromSub($this->calendar($filters), 'calendar')->leftJoinSub($games, 'game', 'game.day', '=', 'calendar.day')
            ->select('calendar.day', 'game.game_id', 'game.league', 'game.game', 'game.game_status', 'game.schedule')
            ->selectRaw('COALESCE(game.gross_sales, 0) as gross_sales, COALESCE(game.payout, 0) as payout')
            ->selectRaw(DB::connection()->getDriverName() === 'sqlite' ? "calendar.day || '-' || COALESCE(game.game_id, 0) as id" : "CONCAT(calendar.day, '-', COALESCE(game.game_id, 0)) as id");

        return DB::query()->fromSub($rows, 'financials')->select('financials.*')->selectRaw('(gross_sales - payout) as ggr')
            ->selectRaw('ROUND((gross_sales - payout) * ?, 2) as pagcor_share', [$this->rate()]);
    }

    /** @param array<string, mixed> $filters */
    private function gameScope(array $filters, bool $lucky): Builder
    {
        $games = DB::table('ending_games as g')->leftJoin('ending_teams as ta', 'ta.id', '=', 'g.team_a')
            ->leftJoin('ending_teams as tb', 'tb.id', '=', 'g.team_b')->leftJoin('ending_leagues as l', 'l.id', '=', 'ta.league_id');
        $this->dates($games, $filters, 'g.schedule');
        if ($lucky) {
            $games->where('g.is_lucky_four', 'Yes');
        }
        if (! empty($filters['league_id'])) {
            $games->where('ta.league_id', $filters['league_id']);
        }
        if (! empty($filters['team_id'])) {
            $games->where(fn (Builder $q) => $q->where('g.team_a', $filters['team_id'])->orWhere('g.team_b', $filters['team_id']));
        }
        match ($filters['status']) {
            'Not Started' => $games->where(fn (Builder $q) => $q->whereNull('g.game_status_id')->orWhere('g.game_status_id', 0)),
            'Finished' => $games->where('g.game_status_id', 5),
            'Ongoing' => $games->whereNotNull('g.game_status_id')->whereNotIn('g.game_status_id', [0, 5]),
            default => null,
        };

        return $games;
    }

    /** @param array<string, mixed> $filters */
    private function transactions(array $filters): Builder
    {
        $transactions = DB::table('player_wallet_transactions as t')->leftJoin('player_wallet_transaction_types as type', 'type.id', '=', 't.transaction_type_id')
            ->leftJoin('admin_outlets as outlet', 'outlet.id', '=', 't.outlet_id')->whereNotIn('t.transaction_type_id', [1, 2])
            ->select('t.id as transaction_id', 't.player_id', 't.amount', 't.created_at as date_time', 't.created_at as time_of_bet', 't.created_at as settle_time', 'outlet.outlet_name as outlet')
            ->selectRaw($this->localDate('t.created_at').' as day')
            ->selectRaw("CASE WHEN t.transaction_type_id = 3 THEN 'Ending Bet' WHEN t.transaction_type_id = 4 THEN 'Ending Win' ELSE type.wallet_transaction_type END as transaction_type")
            ->selectRaw("'RGP' as channel_type, 'Lascasas Gaming' as brand, 'Basketball' as sport, '' as ticket_status, 'Live' as prematch_live, '' as kiosk_terminal, 'Non-virtual' as game_type");
        $this->dates($transactions, $filters, 't.created_at');

        return DB::query()->fromSub($this->calendar($filters), 'calendar')->leftJoinSub($transactions, 't', 't.day', '=', 'calendar.day')
            ->select('calendar.day', ...array_map(fn (array $column): string => 't.'.$column['key'], PagcorReports::columns('transactional')))
            ->selectRaw(DB::connection()->getDriverName() === 'sqlite' ? "calendar.day || '-' || COALESCE(t.transaction_id, 0) as id" : "CONCAT(calendar.day, '-', COALESCE(t.transaction_id, 0)) as id");
    }
}
