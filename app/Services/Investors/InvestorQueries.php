<?php

namespace App\Services\Investors;

use Carbon\CarbonImmutable;
use Hashids\Hashids;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InvestorQueries
{
    public const COLUMNS = ['i.id', 'i.outlet_id', 'i.investor_type_id', 'i.territory', 'i.username',
        'i.first_name', 'i.last_name', 'i.mobile_number', 'i.base_commission', 'i.commission',
        'i.sub_commission', 'i.wallet', 'i.link_type', 'i.category', 'i.status', 'i.created_at'];

    /** @param array<string, mixed> $filters */
    public function investors(array $filters): Builder
    {
        $query = DB::table('admin_investors as i')->leftJoin('admin_outlets as o', 'o.id', '=', 'i.outlet_id')
            ->select([...self::COLUMNS, 'o.outlet_name']);
        if (! empty($filters['search'])) {
            $search = '%'.$filters['search'].'%';
            $query->where(fn (Builder $q) => $q->where('i.first_name', 'like', $search)->orWhere('i.last_name', 'like', $search)
                ->orWhere('i.mobile_number', 'like', $search)->orWhere('i.territory', 'like', $search)->orWhere('i.username', 'like', $search));
        }
        if (! empty($filters['status']) && $filters['status'] !== 'All') {
            $query->where('i.status', $filters['status']);
        }

        return $query;
    }

    /** @param array<string, mixed> $filters */
    public function dates(Builder $query, string $column, array $filters): Builder
    {
        if (! empty($filters['date_from'])) {
            $query->where($column, '>=', CarbonImmutable::parse($filters['date_from'], 'Asia/Manila')->utc());
        }
        if (! empty($filters['date_to'])) {
            $query->where($column, '<', CarbonImmutable::parse($filters['date_to'], 'Asia/Manila')->addDay()->utc());
        }

        return $query;
    }

    /** @return list<int> */
    public function hierarchy(int $investor): array
    {
        $relationships = DB::table('admin_investor_sub_agents')->get(['investor_id', 'investor_agent_id']);
        $edges = $relationships->groupBy('investor_id');
        $parents = $relationships->groupBy('investor_agent_id');
        $ids = [$investor];
        $queue = [$investor];
        while ($queue) {
            $parent = array_shift($queue);
            foreach ($edges->get($parent, collect()) as $edge) {
                $child = (int) $edge->investor_agent_id;
                if (in_array($child, $ids, true) || $parents->get($child)->count() !== 1) {
                    throw ValidationException::withMessages(['investor' => 'The agent hierarchy contains duplicate or circular relationships. Review it before settlement.']);
                }
                $ids[] = $child;
                $queue[] = $child;
            }
        }

        return $ids;
    }

    /** @param array<string, mixed> $filters */
    public function pending(string $kind, int $investor, array $filters, string $ledger = 'base'): Builder
    {
        if ($kind === 'payouts') {
            return $this->dates(DB::table('admin_investor_payouts')->where('investor_id', $investor)
                ->where('status', 'Pending')->whereNull('settlemet_id'), 'created_at', $filters);
        }
        $table = $ledger === 'base' ? 'admin_investor_commissions' : 'admin_investor_sales_representative_commissions';
        $query = DB::table($table)->whereIn('investor_id', $this->hierarchy($investor))
            ->where('is_settled', 'No')->whereNull('settlement_id')->whereNotNull('bet_created_at');
        if ($ledger === 'base') {
            $query->whereNull('special_settlement_id');
        }

        return $this->dates($query, 'bet_created_at', $filters);
    }

    /** @param array<string, mixed> $filters */
    public function payouts(array $filters): Builder
    {
        return $this->dates(DB::table('admin_investor_payouts')->where('status', 'Pending')->whereNull('settlemet_id'), 'created_at', $filters)
            ->select('investor_id')->selectRaw('SUM(amount) as amount, MIN(created_at) as min_date, MAX(created_at) as max_date')
            ->groupBy('investor_id');
    }

    /** @param array<string, mixed> $filters
     * @return array{bet_count: int, bet_amount: string}
     */
    public function betSummary(int $investor, array $filters): array
    {
        $ids = $this->hierarchy($investor);
        $query = DB::table('ending_bets as b')->join('ending_cards as c', 'c.id', '=', 'b.card_id')
            ->join('ending_bet_amounts as a', 'a.id', '=', 'c.bet_amount_id')
            ->whereExists(fn (Builder $players) => $players->selectRaw('1')->from('admin_investor_sub_agent_players as p')
                ->whereIn('p.investor_agent_id', $ids)->where(fn (Builder $identity) => $identity
                ->where(fn (Builder $legacy) => $legacy->whereNotNull('p.legacy_player_id')->whereColumn('p.legacy_player_id', 'b.legacy_player_id'))
                ->orWhere(fn (Builder $local) => $local->whereNull('p.legacy_player_id')->whereColumn('p.player_id', 'b.player_id'))));
        $totals = $this->dates($query, 'b.created_at', $filters)->selectRaw('COUNT(b.id) as bet_count, COALESCE(SUM(a.amount), 0) as bet_amount')->first();

        return ['bet_count' => (int) $totals->bet_count, 'bet_amount' => (string) $totals->bet_amount];
    }

    /** @param array<string, mixed> $filters */
    public function special(array $filters): Builder
    {
        $logs = DB::table('admin_investor_special_settlement_logs')->select('special_settlement_id', 'status')
            ->selectRaw('MAX(process_date) as process_date')->groupBy('special_settlement_id', 'status');
        $query = DB::table('admin_investor_special_settlement as s')->leftJoin('admin_investors as i', 'i.id', '=', 's.investor_id')
            ->leftJoinSub($logs, 'l', fn ($join) => $join->on('l.special_settlement_id', '=', 's.id')->on('l.status', '=', 's.status'))
            ->select(['s.id', 's.investor_id', 'i.first_name', 'i.last_name', 's.amount', 's.status', 's.mode_of_payment', 's.reference', 's.remarks', 'l.process_date'])
            ->selectSub(DB::table('admin_investor_commissions as c')->whereColumn('c.special_settlement_id', 's.id')->selectRaw('COUNT(*)'), 'items');
        if (! empty($filters['search'])) {
            $query->where(fn (Builder $q) => $q->where('i.first_name', 'like', '%'.$filters['search'].'%')->orWhere('i.last_name', 'like', '%'.$filters['search'].'%'));
        }
        if (! empty($filters['status']) && $filters['status'] !== 'All') {
            $query->where('s.status', $filters['status']);
        }

        return $this->dates($query, 'l.process_date', $filters)->orderByDesc('s.id');
    }

    /** @param array<string, mixed> $filters */
    public function referrals(array $filters): Builder
    {
        return $this->investors($filters)->join('admin_investor_referral_links as r', 'r.investor_id', '=', 'i.id')
            ->addSelect(['r.id as referral_id', 'r.link_type as referral_type'])->orderBy('r.id');
    }

    public function referralUrl(string $type, ?string $territory, int $id, bool $old = false): ?string
    {
        if (! in_array($type, ['SM', 'SS', 'SR', 'RP'], true) || (! $old && ! $territory)) {
            return null;
        }
        if ($old) {
            $hash = new Hashids('referral_code', 5);

            return in_array($type, ['SM', 'SS'], true)
                ? 'https://partners.gamexsports.com.ph/register/'.$hash->encode(2).'/'.$hash->encode($id)
                : 'https://gamexsports.com.ph/'.$hash->encode(2).'/'.$hash->encode(1).'/'.$hash->encode($id);
        }
        $code = rawurlencode($territory.'-'.$id);

        return in_array($type, ['SM', 'SS'], true)
            ? 'https://partners.gamexsports.com.ph/register/'.$code
            : 'https://gamexsports.com.ph/?referral_code='.$code;
    }
}
