<?php

namespace App\Services\Players;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class PlayerCashierQueries
{
    /** @param array<string, mixed> $filters */
    public function filtered(string $kind, array $filters): Builder
    {
        $query = DB::table('player_'.$kind.' as c')
            ->leftJoin('players as p', 'p.id', '=', 'c.player_id')
            ->leftJoin('admin_player_banks as b', 'b.id', '=', 'c.bank_id')
            ->leftJoin('player_wallet as w', 'w.player_id', '=', 'p.id')
            ->leftJoin('users as u', 'u.id', '=', 'c.evaluated_by')
            ->select(['c.id', 'p.id as player_id', 'p.name as player_name', 'p.mobile_number', 'b.bank',
                'w.actual_amount as wallet', 'c.account_number', 'c.amount', 'c.type', 'c.remarks', 'c.status',
                'c.created_at', 'c.evaluated_at', 'u.name as evaluated_by']);
        if ($kind === 'cashin') {
            $query->leftJoin('admin_player_fund_accounts as f', 'f.id', '=', 'c.bank_account_id')
                ->addSelect(['c.reference_number', 'c.notes', 'f.account_number as receiver_account'])
                ->selectRaw("CASE WHEN c.screen_shot LIKE 'https://%' THEN c.screen_shot ELSE NULL END as receipt_url");
        } else {
            $query->addSelect('c.account_name');
        }
        if (($filters['filter'] ?? 'Pending') !== 'All') {
            $query->where('c.status', $filters['filter']);
        }
        $dateColumn = in_array($filters['filter'] ?? 'Pending', ['All', 'Pending'], true) ? 'c.created_at' : 'c.evaluated_at';
        if (! empty($filters['date_from'])) {
            $query->where($dateColumn, '>=', CarbonImmutable::parse($filters['date_from'], 'Asia/Manila')->startOfDay()->utc());
        }
        if (! empty($filters['date_to'])) {
            $query->where($dateColumn, '<', CarbonImmutable::parse($filters['date_to'], 'Asia/Manila')->addDay()->startOfDay()->utc());
        }
        if (($filters['search'] ?? '') !== '') {
            $query->where(function (Builder $search) use ($kind, $filters): void {
                $columns = ['p.name', 'p.mobile_number', 'b.bank', 'c.account_number', 'c.remarks'];
                $columns[] = $kind === 'cashin' ? 'c.reference_number' : 'c.account_name';
                foreach ($columns as $column) {
                    $search->orWhere($column, 'like', '%'.$filters['search'].'%');
                }
            });
        }
        $sorts = ['player_name' => 'p.name', 'mobile_number' => 'p.mobile_number', 'bank' => 'b.bank',
            'wallet' => 'w.actual_amount', 'amount' => 'c.amount', 'type' => 'c.type', 'remarks' => 'c.remarks',
            'status' => 'c.status', 'created_at' => 'c.created_at', 'evaluated_at' => 'c.evaluated_at'];

        return $query->orderBy($sorts[$filters['sort'] ?? 'created_at'], $filters['direction'] ?? 'desc')->orderByDesc('c.id');
    }
}
