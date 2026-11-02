<?php

namespace App\Services\Agents;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

class AgentQueries
{
    /** @param array<string, mixed> $filters */
    public function accounts(array $filters): Builder
    {
        $counts = DB::table('admin_ending_agent_players as link')->whereNull('link.detached_at')
            ->leftJoin('player_profiles as profile', 'profile.player_id', '=', 'link.player_id')
            ->select('link.agent_id')->selectRaw('COUNT(*) as player_count')
            ->selectRaw("SUM(CASE WHEN profile.kyc_status = 'Verified' THEN 1 ELSE 0 END) as verified_count")
            ->groupBy('link.agent_id');
        $query = DB::table('admin_ending_agents as a')->leftJoin('admin_ending_agent_info as i', 'i.agent_id', '=', 'a.id')
            ->leftJoin('admin_outlets as outlet', 'outlet.id', '=', 'a.outlet_id')
            ->leftJoinSub($counts, 'counts', 'counts.agent_id', '=', 'a.id')
            ->select(['a.id', 'a.outlet_id', 'a.username', 'a.is_active', 'a.type', 'a.commission', 'a.wallet', 'a.created_at',
                'i.first_name', 'i.middle_name', 'i.last_name', 'i.mobile_number', 'i.email_address', 'i.sub_type', 'i.page_name', 'outlet.outlet_name'])
            ->selectRaw('COALESCE(counts.player_count, 0) as player_count, COALESCE(counts.verified_count, 0) as verified_count')
            ->selectSub(DB::table('admin_ending_agents as duplicate')->selectRaw('COUNT(*)')->whereColumn('duplicate.username', 'a.username')->whereColumn('duplicate.id', '!=', 'a.id'), 'username_conflicts');
        if ($filters['search'] ?? '') {
            $search = '%'.$filters['search'].'%';
            $query->where(fn (Builder $q) => $q->where('i.first_name', 'like', $search)->orWhere('i.last_name', 'like', $search)
                ->orWhere('i.mobile_number', 'like', $search)->orWhere('i.email_address', 'like', $search)->orWhere('a.username', 'like', $search));
        }
        foreach (['status' => 'a.is_active', 'type' => 'a.type', 'outlet_id' => 'a.outlet_id'] as $filter => $column) {
            if (($filters[$filter] ?? 'All') !== 'All' && ($filters[$filter] ?? '') !== '') {
                $query->where($column, $filters[$filter]);
            }
        }
        $this->dates($query, 'a.created_at', $filters);

        return $query;
    }

    /** @param array<string, mixed> $filters */
    public function cashins(array $filters): Builder
    {
        $query = DB::table('player_cashin as c')
            ->leftJoin('admin_ending_agent_players as link', function (JoinClause $join): void {
                $join->on('link.player_id', '=', 'c.player_id')->whereNull('link.detached_at');
            })->leftJoin('admin_ending_agent_players as unresolved', function (JoinClause $join): void {
                $join->on('unresolved.legacy_player_id', '=', 'c.legacy_player_id')
                    ->whereNull('c.player_id')->whereNull('unresolved.player_id')->whereNull('unresolved.detached_at');
            })->join('admin_ending_agents as a', 'a.id', '=', DB::raw('COALESCE(link.agent_id, unresolved.agent_id)'))
            ->leftJoin('players as player', 'player.id', '=', 'c.player_id')
            ->select(['c.player_id', 'player.name as player_name']);
        $query->leftJoin('admin_ending_agent_info as i', 'i.agent_id', '=', 'a.id')
            ->leftJoin('admin_player_fund_accounts as account', 'account.id', '=', 'c.bank_account_id')
            ->leftJoin('admin_player_banks as bank', 'bank.id', '=', 'c.bank_id')
            ->leftJoin('users as evaluator', 'evaluator.id', '=', 'c.evaluated_by')
            ->addSelect(['c.id', 'a.id as agent_id', 'i.first_name', 'i.middle_name', 'i.last_name', 'a.type as agent_type',
                'bank.bank as payment_method', 'account.account_number as receiver_account', 'c.reference_number', 'c.amount', 'c.type', 'c.notes', 'c.remarks',
                'c.status', 'c.created_at', 'c.evaluated_at', 'evaluator.name as evaluated_by'])
            ->selectRaw("CASE WHEN c.screen_shot LIKE 'https://%' THEN c.screen_shot ELSE NULL END as receipt_url");
        if (($filters['status'] ?? 'All') !== 'All') {
            $query->where('c.status', $filters['status']);
        }
        if ($filters['agent_id'] ?? null) {
            $query->where('a.id', $filters['agent_id']);
        }
        if ($filters['search'] ?? '') {
            $search = '%'.$filters['search'].'%';
            $query->where(fn (Builder $q) => $q->where('i.first_name', 'like', $search)->orWhere('i.last_name', 'like', $search)
                ->orWhere('c.reference_number', 'like', $search)->orWhere('player.name', 'like', $search));
        }
        $column = in_array($filters['status'] ?? 'All', ['Approved', 'Disapproved', 'Rejected'], true) ? 'c.evaluated_at' : 'c.created_at';
        $this->dates($query, $column, $filters);

        return $query;
    }

    /** @param array<string, mixed> $filters */
    private function dates(Builder $query, string $column, array $filters): void
    {
        if ($filters['date_from'] ?? '') {
            $query->where($column, '>=', CarbonImmutable::parse($filters['date_from'], 'Asia/Manila')->startOfDay()->utc());
        }
        if ($filters['date_to'] ?? '') {
            $query->where($column, '<', CarbonImmutable::parse($filters['date_to'], 'Asia/Manila')->addDay()->startOfDay()->utc());
        }
    }
}
