<?php

namespace App\Services\Players;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

class PlayerReportQueries
{
    /** @param array<string, mixed> $filters */
    public function filtered(string $report, array $filters): Builder
    {
        $query = DB::table('players as p')
            ->leftJoin('player_profiles as i', 'i.player_id', '=', 'p.id')
            ->leftJoin('barangays as barangay', 'barangay.id', '=', 'i.barangay_id')
            ->leftJoin('cities as city', 'city.id', '=', 'barangay.city_id')
            ->select(['p.id', 'p.name', 'p.mobile_number', 'city.name as city_municipality']);

        if ($report === 'active') {
            $this->activity($query, $filters);
        } else {
            $query->addSelect(['p.email as email_address', 'p.status as active_status',
                'i.kyc_status as account_verified', 'p.created_at']);
            $this->dates($query, $filters, 'p.created_at');
            if ($filters['status'] !== 'All') {
                $query->where('p.status', $filters['status']);
            }
            if ($filters['account_verified'] !== 'All') {
                $query->where('i.kyc_status', $filters['account_verified']);
            }
        }

        if (($filters['search'] ?? '') !== '') {
            $search = '%'.$filters['search'].'%';
            $query->where(function (Builder $q) use ($search, $report): void {
                $q->where('p.name', 'like', $search)->orWhere('p.mobile_number', 'like', $search);
                if ($report === 'active') {
                    $q->orWhere('city.name', 'like', $search);
                } else {
                    $q->orWhere('p.email', 'like', $search);
                }
            });
        }

        // Order aliases in an outer query so calculated totals can also be filtered and exported.
        return DB::query()->fromSub($query, 'report')
            ->orderBy($filters['sort'], $filters['direction'])->orderBy('id', $filters['direction']);
    }

    /** @return list<string> */
    public static function sortColumns(string $report): array
    {
        return $report === 'active'
            ? ['id', 'name', 'mobile_number', 'city_municipality', 'age', 'gender', 'total_bet_count',
                'ending_amount', 'lucky_four_amount', 'total_bets', 'total_payouts', 'net_amount']
            : ['id', 'name', 'mobile_number', 'email_address', 'account_verified', 'active_status', 'created_at', 'city_municipality'];
    }

    /** @param array<string, mixed> $filters */
    private function activity(Builder $query, array $filters): void
    {
        $bets = DB::table('ending_bets as b')->select('b.player_id')->selectRaw('COUNT(*) as total_bet_count');
        $this->dates($bets, $filters, 'b.created_at');
        $bets->groupBy('b.player_id');

        $ending = DB::table('ending_bets as b')->join('ending_cards as c', 'c.id', '=', 'b.card_id')
            ->join('ending_bet_amounts as a', 'a.id', '=', 'c.bet_amount_id')
            ->select('b.player_id')->selectRaw('SUM(a.amount) as amount')->groupBy('b.player_id');
        $this->dates($ending, $filters, 'b.created_at');

        $lucky = DB::table('ending_bets as b')->join('lucky_four_bets as l', 'l.ending_bet_id', '=', 'b.id')
            ->select('b.player_id')->selectRaw('SUM(l.amount) as amount')->groupBy('b.player_id');
        $this->dates($lucky, $filters, 'b.created_at');

        $payouts = DB::table('ending_game_winners as w')->join('ending_bets as b', 'b.id', '=', 'w.bet_id')
            ->join('ending_cards as c', 'c.id', '=', 'b.card_id')
            ->join('ending_bet_amounts as a', 'a.id', '=', 'c.bet_amount_id')
            ->join('ending_bet_amount_prizes as prize', fn (JoinClause $join) => $join
                ->on('prize.bet_amount_id', '=', 'a.id')->on('prize.quarter_id', '=', 'w.quarter_id'))
            ->where('w.prize_released', 'Yes')->where('prize.is_cash', 'Cash')->where('b.game_type_id', 1)
            ->select('b.player_id')->selectRaw('SUM(prize.cash_prize) as amount')->groupBy('b.player_id');
        $this->dates($payouts, $filters, 'w.created_at');

        $query->joinSub($bets, 'bets', 'bets.player_id', '=', 'p.id')
            ->leftJoinSub($ending, 'ending', 'ending.player_id', '=', 'p.id')
            ->leftJoinSub($lucky, 'lucky', 'lucky.player_id', '=', 'p.id')
            ->leftJoinSub($payouts, 'payouts', 'payouts.player_id', '=', 'p.id')
            ->addSelect('bets.total_bet_count')->selectRaw("COALESCE(i.gender, 'N/A') as gender")
            ->selectRaw('COALESCE(ending.amount, 0) as ending_amount, COALESCE(lucky.amount, 0) as lucky_four_amount')
            ->selectRaw('COALESCE(ending.amount, 0) + COALESCE(lucky.amount, 0) as total_bets')
            ->selectRaw('COALESCE(payouts.amount, 0) as total_payouts')
            ->selectRaw('COALESCE(ending.amount, 0) + COALESCE(lucky.amount, 0) - COALESCE(payouts.amount, 0) as net_amount');

        $age = DB::getDriverName() === 'sqlite'
            ? 'CAST((julianday(?) - julianday(i.birthdate)) / 365.25 AS INTEGER)'
            : 'FLOOR(DATEDIFF(?, i.birthdate) / 365.25)';
        $query->selectRaw($age.' as age', [now('Asia/Manila')->format('Y-m-d')]);
    }

    /** @param array<string, mixed> $filters */
    private function dates(Builder $query, array $filters, string $column): void
    {
        if (! empty($filters['date_from'])) {
            $query->where($column, '>=', CarbonImmutable::parse($filters['date_from'], 'Asia/Manila')->startOfDay()->utc());
        }
        if (! empty($filters['date_to'])) {
            $query->where($column, '<', CarbonImmutable::parse($filters['date_to'], 'Asia/Manila')->addDay()->startOfDay()->utc());
        }
    }
}
