<?php

namespace App\Services\Ending;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

class EndingReportQueries
{
    /** @param array<string, mixed> $filters */
    public function filtered(string $report, array $filters): Builder
    {
        $query = match ($report) {
            'bets' => $this->bets($filters),
            'game-card' => $this->cards($filters),
            'game', 'payouts' => $this->games($filters, $report === 'payouts'),
            'progress' => $this->progress($filters),
            'transactional' => $this->transactions($filters),
            'cash-in', 'cash-out' => $this->cashier($filters, $report === 'cash-in' ? 'player_cashin' : 'player_cashout'),
            'cards' => $this->cardTypes($filters),
            'top-winning-combination' => $this->combinations($filters),
            'master-agents-token' => $this->agents($filters),
            default => throw new \InvalidArgumentException('Unknown Ending report.'),
        };
        $outer = $query->newQuery()->fromSub($query, 'report');
        if (($filters['search'] ?? '') !== '') {
            $keys = array_column(array_filter(EndingReports::columns($report), fn (array $column): bool => $column['format'] === 'text'), 'key');
            $outer->where(function (Builder $q) use ($keys, $filters): void {
                foreach ($keys as $key) {
                    $q->orWhere($key, 'like', '%'.$filters['search'].'%');
                }
            });
        }

        return $outer->orderBy($filters['sort'], $filters['direction'])->orderBy('id', $filters['direction']);
    }

    /** @param array<string, mixed> $filters */
    private function gameScope(array $filters): Builder
    {
        $query = DB::table('ending_games as g')->leftJoin('ending_teams as ta', 'ta.id', '=', 'g.team_a')
            ->leftJoin('ending_teams as tb', 'tb.id', '=', 'g.team_b')->leftJoin('ending_leagues as l', 'l.id', '=', 'ta.league_id');
        foreach (['league_id' => 'ta.league_id', 'game_id' => 'g.id'] as $filter => $column) {
            if (! empty($filters[$filter])) {
                $query->where($column, $filters[$filter]);
            }
        }
        if (! empty($filters['team_id'])) {
            $query->where(fn (Builder $q) => $q->where('g.team_a', $filters['team_id'])->orWhere('g.team_b', $filters['team_id']));
        }
        match ($filters['status']) {
            'Not Started' => $query->where(fn (Builder $q) => $q->whereNull('g.game_status_id')->orWhere('g.game_status_id', 0)),
            'Ongoing' => $query->whereBetween('g.game_status_id', [1, 4]),
            'Finished' => $query->where('g.game_status_id', 5),
            default => null,
        };

        return $query;
    }

    /** @param array<string, mixed> $filters */
    private function betScope(array $filters): Builder
    {
        $query = $this->gameScope($filters)->join('ending_bet_amounts as a', 'a.game_id', '=', 'g.id')
            ->join('ending_cards as c', 'c.bet_amount_id', '=', 'a.id')->join('ending_bets as b', 'b.card_id', '=', 'c.id')
            ->where('b.game_type_id', 1);
        foreach (['card_id' => 'c.id', 'slot_id' => 'b.slot_id', 'player_id' => 'b.player_id', 'bet_type_id' => 'a.bet_type_id'] as $filter => $column) {
            if (! empty($filters[$filter])) {
                $query->where($column, $filters[$filter]);
            }
        }
        if ($filters['filter_by'] === 'scheduled') {
            $this->dates($query, $filters, 'g.schedule');
        }

        return $query;
    }

    /** @param array<string, mixed> $filters */
    private function winners(array $filters, string $activityDate = 'b.created_at'): Builder
    {
        $amounts = $this->betScope($filters)->select('a.id');
        if ($filters['filter_by'] === 'bet') {
            if ($activityDate === 'w.created_at') {
                $amounts->join('ending_game_winners as w', 'w.bet_id', '=', 'b.id');
            }
            $this->dates($amounts, $filters, $activityDate);
        }
        $prizes = DB::table('ending_bet_amount_prizes')->where('is_cash', 'Cash')
            ->whereIn('bet_amount_id', $amounts)
            ->select(['bet_amount_id', 'quarter_id'])->selectRaw('SUM(cash_prize) as cash_prize')->groupBy('bet_amount_id', 'quarter_id');

        return $this->betScope($filters)->join('ending_game_winners as w', 'w.bet_id', '=', 'b.id')
            ->leftJoinSub($prizes, 'prize', fn (JoinClause $join) => $join
                ->on('prize.bet_amount_id', '=', 'a.id')->on('prize.quarter_id', '=', 'w.quarter_id'));
    }

    /** @param array<string, mixed> $filters */
    private function dates(Builder $query, array $filters, string $column): void
    {
        $from = CarbonImmutable::parse($filters['date_from'], 'Asia/Manila')->startOfDay()->utc();
        $until = CarbonImmutable::parse($filters['date_to'], 'Asia/Manila')->addDay()->startOfDay()->utc();
        $query->where($column, '>=', $from)->where($column, '<', $until);
    }

    /** @return literal-string */
    private function gameName(): string
    {
        return DB::connection()->getDriverName() === 'sqlite'
            ? "COALESCE(ta.team_name, '') || ' ' || COALESCE(ta.team_alias, '') || ' vs ' || COALESCE(tb.team_name, '') || ' ' || COALESCE(tb.team_alias, '')"
            : "CONCAT(COALESCE(ta.team_name, ''), ' ', COALESCE(ta.team_alias, ''), ' vs ', COALESCE(tb.team_name, ''), ' ', COALESCE(tb.team_alias, ''))";
    }

    /** @param array<string, mixed> $filters */
    private function bets(array $filters): Builder
    {
        $payouts = $this->winners($filters)->where('w.prize_released', 'Yes')->select('b.id')
            ->selectRaw('SUM(COALESCE(prize.cash_prize, 0)) as payout')->groupBy('b.id');
        $this->dates($payouts, $filters, $filters['filter_by'] === 'scheduled' ? 'g.schedule' : 'b.created_at');
        $query = $this->betScope($filters)->leftJoin('players as p', 'p.id', '=', 'b.player_id')
            ->leftJoin('ending_slots as slot', 'slot.id', '=', 'b.slot_id')->leftJoin('ending_bet_types as bt', 'bt.id', '=', 'a.bet_type_id')
            ->leftJoinSub($payouts, 'paid', 'paid.id', '=', 'b.id')
            ->select(['b.id', 'b.player_id', 'p.mobile_number', 'l.league', 'g.schedule', 'b.card_id', 'a.amount', 'slot.slot',
                'bt.type', 'b.quarter_id', 'b.result', 'b.created_at', 'g.id as game_id'])
            ->selectRaw("COALESCE(NULLIF(b.name, ''), p.name) as name, COALESCE(paid.payout, 0) as payout")
            ->selectRaw($this->gameName().' as game');
        $this->dates($query, $filters, $filters['filter_by'] === 'scheduled' ? 'g.schedule' : 'b.created_at');

        return $query;
    }

    /** @param array<string, mixed> $filters */
    private function cards(array $filters): Builder
    {
        $bets = $this->betScope($filters)->select('c.id')->selectRaw('COUNT(*) as bets, SUM(a.amount) as gross_sales')->groupBy('c.id');
        $payouts = $this->winners($filters)->where('w.prize_released', 'Yes')->select('c.id')
            ->selectRaw('SUM(COALESCE(prize.cash_prize, 0)) as payout')->groupBy('c.id');
        if ($filters['filter_by'] === 'bet') {
            $this->dates($bets, $filters, 'b.created_at');
            $this->dates($payouts, $filters, 'b.created_at');
        }
        $query = $this->gameScope($filters)->join('ending_bet_amounts as a', 'a.game_id', '=', 'g.id')
            ->join('ending_cards as c', 'c.bet_amount_id', '=', 'a.id')->leftJoin('ending_bet_types as bt', 'bt.id', '=', 'a.bet_type_id')
            ->leftJoinSub($bets, 'bets', 'bets.id', '=', 'c.id')->leftJoinSub($payouts, 'paid', 'paid.id', '=', 'c.id')
            ->select(['c.id', 'g.id as game_id', 'l.league', 'g.schedule', 'bt.type', 'a.amount', 'c.created_at', 'a.bet_type_id'])
            ->selectRaw($this->gameName().' as game')->selectRaw('COALESCE(bets.bets, 0) as bets, COALESCE(bets.gross_sales, 0) as gross_sales, COALESCE(paid.payout, 0) as payout');
        if ($filters['filter_by'] === 'scheduled') {
            $this->dates($query, $filters, 'g.schedule');
        } else {
            $query->whereNotNull('bets.id');
        }
        foreach (['card_id' => 'c.id', 'bet_type_id' => 'a.bet_type_id'] as $filter => $column) {
            if (! empty($filters[$filter])) {
                $query->where($column, $filters[$filter]);
            }
        }

        return $query;
    }

    /** @param array<string, mixed> $filters */
    private function games(array $filters, bool $payoutOnly): Builder
    {
        $bets = $this->betScope($filters)->select('g.id')->selectRaw('SUM(a.amount) as gross_sales')->groupBy('g.id');
        $paid = $this->winners($filters)->where('w.prize_released', 'Yes')->select('g.id')
            ->selectRaw('SUM(COALESCE(prize.cash_prize, 0)) as payout')
            ->selectRaw('SUM(CASE WHEN a.bet_type_id = 1 THEN COALESCE(prize.cash_prize, 0) ELSE 0 END) as traditional')
            ->selectRaw('SUM(CASE WHEN a.bet_type_id = 2 THEN COALESCE(prize.cash_prize, 0) ELSE 0 END) as per_quarter')->groupBy('g.id');
        if ($filters['filter_by'] === 'bet') {
            $this->dates($bets, $filters, 'b.created_at');
            $this->dates($paid, $filters, 'b.created_at');
        }
        $query = $this->gameScope($filters)->leftJoinSub($bets, 'bets', 'bets.id', '=', 'g.id')->leftJoinSub($paid, 'paid', 'paid.id', '=', 'g.id')
            ->select(['g.id', 'l.league', 'g.schedule'])->selectRaw($this->gameName().' as game')
            ->selectRaw("CASE WHEN COALESCE(g.game_status_id, 0) = 0 THEN 'Not Started' WHEN g.game_status_id = 5 THEN 'Finished' ELSE 'Ongoing' END as game_status")
            ->selectRaw('COALESCE(bets.gross_sales, 0) as gross_sales, COALESCE(paid.payout, 0) as payout, COALESCE(paid.traditional, 0) as traditional, COALESCE(paid.per_quarter, 0) as per_quarter');
        if ($filters['filter_by'] === 'scheduled') {
            $this->dates($query, $filters, 'g.schedule');
        } else {
            $query->where(fn (Builder $q) => $q->whereNotNull('bets.id')->orWhereNotNull('paid.id'));
        }
        if ($payoutOnly) {
            $query->where('paid.payout', '>', 0);
        }

        return $this->financials($query);
    }

    private function financials(Builder $query): Builder
    {
        $rate = (float) (DB::table('ending_config')->where('config_name', 'PAGCOR Percentage')->value('config_value')
            ?? DB::connection(config('ending.reports.agent_connection', 'legacy'))->table('ending_config')
                ->where('config_name', 'PAGCOR Percentage')->soleValue('config_value'));

        return DB::query()->fromSub($query, 'financials')->select('financials.*')
            ->selectRaw('(gross_sales - payout) as ggr')
            ->selectRaw('ROUND((gross_sales - payout) * ? / 100, 2) as pagcor_share', [$rate])
            ->selectRaw('(gross_sales - payout) - ROUND((gross_sales - payout) * ? / 100, 2) as net_income', [$rate]);
    }

    /** @param array<string, mixed> $filters */
    private function cardTypes(array $filters): Builder
    {
        $cards = $this->cards($filters)->select(['c.id', 'a.bet_type_id'])
            ->selectRaw('COALESCE(bets.bets, 0) as bets, COALESCE(bets.gross_sales, 0) as gross_sales, COALESCE(paid.payout, 0) as payout');
        $totals = DB::query()->fromSub($cards, 'cards')->select('bet_type_id')
            ->selectRaw('COUNT(*) as cards, SUM(bets) as bets, SUM(gross_sales) as gross_sales, SUM(payout) as payout')->groupBy('bet_type_id');
        $query = DB::table('ending_bet_types as bt')->leftJoinSub($totals, 'totals', 'totals.bet_type_id', '=', 'bt.id')
            ->select(['bt.id', 'bt.type'])->selectRaw('COALESCE(totals.cards, 0) as cards, COALESCE(totals.bets, 0) as bets, COALESCE(totals.gross_sales, 0) as gross_sales, COALESCE(totals.payout, 0) as payout');
        if (! empty($filters['bet_type_id'])) {
            $query->where('bt.id', $filters['bet_type_id']);
        }

        return $this->financials($query);
    }

    /**
     * @param  literal-string  $column
     * @return literal-string
     */
    private function period(string $column, string $period): string
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            $date = "datetime($column, '+8 hours')";

            return match ($period) {
                'weekly' => "date($date, '-6 days', 'weekday 1')",
                'monthly' => "strftime('%Y-%m', $date)",
                'yearly' => "strftime('%Y', $date)",
                default => "date($date)",
            };
        }
        $date = "DATE_ADD($column, INTERVAL 8 HOUR)";

        return match ($period) {
            'weekly' => "DATE_FORMAT(DATE_SUB($date, INTERVAL WEEKDAY($date) DAY), '%Y-%m-%d')",
            'monthly' => "DATE_FORMAT($date, '%Y-%m')",
            'yearly' => "DATE_FORMAT($date, '%Y')",
            default => "DATE_FORMAT($date, '%Y-%m-%d')",
        };
    }

    /** @param array<string, mixed> $filters */
    private function progress(array $filters): Builder
    {
        $betDate = $filters['filter_by'] === 'scheduled' ? 'g.schedule' : 'b.created_at';
        $prizeDate = $filters['filter_by'] === 'scheduled' ? 'g.schedule' : 'w.created_at';
        $bets = $this->betScope($filters)->select(['g.id as game_id', 'l.id as league_id', 'l.league', 'b.player_id'])
            ->selectRaw($this->period($betDate, $filters['period']).' as period, 1 as bets, a.amount as gross_sales, 0 as payout');
        $this->dates($bets, $filters, $betDate);
        $paid = $this->winners($filters, 'w.created_at')->where('w.prize_released', 'Yes')->select(['g.id as game_id', 'l.id as league_id', 'l.league'])
            ->selectRaw('NULL as player_id')->selectRaw($this->period($prizeDate, $filters['period']).' as period, 0 as bets, 0 as gross_sales, COALESCE(prize.cash_prize, 0) as payout');
        $this->dates($paid, $filters, $prizeDate);
        $facts = $bets->unionAll($paid);
        if ($filters['filter_by'] === 'scheduled') {
            $games = $this->gameScope($filters)->select(['g.id as game_id', 'l.id as league_id', 'l.league'])
                ->selectRaw('NULL as player_id')->selectRaw($this->period('g.schedule', $filters['period']).' as period, 0 as bets, 0 as gross_sales, 0 as payout');
            $this->dates($games, $filters, 'g.schedule');
            $facts->unionAll($games);
        }
        $id = DB::connection()->getDriverName() === 'sqlite' ? "period || '-' || COALESCE(league_id, 0)" : "CONCAT(period, '-', COALESCE(league_id, 0))";
        $query = DB::query()->fromSub($facts, 'facts')->select(['period', 'league'])
            ->selectRaw($id.' as id, COUNT(DISTINCT game_id) as games, COUNT(DISTINCT player_id) as bettors, SUM(bets) as bets, SUM(gross_sales) as gross_sales, SUM(payout) as payout')
            ->groupBy('period', 'league_id', 'league');

        return $this->financials($query);
    }

    /** @param array<string, mixed> $filters */
    private function transactions(array $filters): Builder
    {
        $query = DB::table('player_wallet_transactions as t')->leftJoin('players as p', 'p.id', '=', 't.player_id')
            ->leftJoin('player_wallet_transaction_types as tt', 'tt.id', '=', 't.transaction_type_id')
            ->where('t.transaction_type_id', '!=', 1)->select(['t.id', 't.created_at', 't.player_id', 'p.name', 'p.mobile_number', 't.amount', 't.description', 'tt.wallet_transaction_type as transaction_type']);
        $this->dates($query, $filters, 't.created_at');
        if (! empty($filters['player_id'])) {
            $query->where('t.player_id', $filters['player_id']);
        }

        return $query;
    }

    /** @param array<string, mixed> $filters */
    private function cashier(array $filters, string $table): Builder
    {
        $query = DB::table($table.' as cash')->leftJoin('players as p', 'p.id', '=', 'cash.player_id')
            ->leftJoin('player_profiles as profile', 'profile.player_id', '=', 'p.id')->leftJoin('barangays as barangay', 'barangay.id', '=', 'profile.barangay_id')
            ->leftJoin('cities as city', 'city.id', '=', 'barangay.city_id');
        $this->dates($query, $filters, 'cash.created_at');
        if ($filters['cashier_status'] !== 'All') {
            $query->where('cash.status', $filters['cashier_status']);
        }
        if (! empty($filters['player_id'])) {
            $query->where('cash.player_id', $filters['player_id']);
        }

        return $query->selectRaw('COALESCE(cash.player_id, -COALESCE(cash.legacy_player_id, cash.id)) as id')
            ->addSelect(['p.name', 'p.mobile_number', 'city.name as city'])->selectRaw('COUNT(*) as transactions, SUM(cash.amount) as amount')
            ->groupByRaw('COALESCE(cash.player_id, -COALESCE(cash.legacy_player_id, cash.id)), p.name, p.mobile_number, city.name');
    }

    /** @param array<string, mixed> $filters */
    private function combinations(array $filters): Builder
    {
        $winners = $this->winners($filters)->select('b.id as bet_id')->addSelect('b.slot_id')
            ->selectRaw("COUNT(w.id) as winners, MAX(a.amount) as amount, SUM(CASE WHEN w.prize_released = 'Yes' THEN COALESCE(prize.cash_prize, 0) ELSE 0 END) as payout")
            ->groupBy('b.id', 'b.slot_id');
        $this->dates($winners, $filters, $filters['filter_by'] === 'scheduled' ? 'g.schedule' : 'b.created_at');

        return DB::query()->fromSub($winners, 'winning')->leftJoin('ending_slots as slot', 'slot.id', '=', 'winning.slot_id')
            ->select(['winning.slot_id as id', 'slot.slot'])->selectRaw('SUM(winners) as winners, COUNT(*) as bets, SUM(amount) as gross_sales, SUM(payout) as payout')
            ->groupBy('winning.slot_id', 'slot.slot');
    }

    /** @param array<string, mixed> $filters */
    private function agents(array $filters): Builder
    {
        $connection = DB::connection(config('ending.reports.agent_connection', 'legacy'));
        $players = $connection->table('ending_agent_players')->select('agent_id')->selectRaw('COUNT(*) as players')->groupBy('agent_id');
        $bets = $connection->table('ending_bets as b')->join('ending_agent_players as ap', 'ap.player_id', '=', 'b.player_id')
            ->join('ending_cards as c', 'c.id', '=', 'b.card_id')->join('ending_bet_amounts as a', 'a.id', '=', 'c.bet_amount_id')
            ->where('b.game_type_id', 1)->where('b.created_at', '>=', $filters['date_from'].' 00:00:00')
            ->where('b.created_at', '<', CarbonImmutable::parse($filters['date_to'])->addDay()->toDateString().' 00:00:00')
            ->select('ap.agent_id')->selectRaw('COUNT(*) as bets, SUM(a.amount) as gross_sales')->groupBy('ap.agent_id');
        $name = $connection->getDriverName() === 'sqlite'
            ? "TRIM(COALESCE(info.first_name, '') || ' ' || COALESCE(info.last_name, ''))"
            : "TRIM(CONCAT(COALESCE(info.first_name, ''), ' ', COALESCE(info.last_name, '')))";

        return $connection->table('ending_agents as agent')->leftJoin('ending_agent_info as info', 'info.agent_id', '=', 'agent.id')
            ->leftJoinSub($players, 'players', 'players.agent_id', '=', 'agent.id')->leftJoinSub($bets, 'bets', 'bets.agent_id', '=', 'agent.id')
            ->select(['agent.id', 'agent.wallet as token'])->selectRaw($name.' as name')
            ->selectRaw('COALESCE(players.players, 0) as players, COALESCE(bets.bets, 0) as bets, COALESCE(bets.gross_sales, 0) as gross_sales');
    }
}
