<?php

namespace App\Services\Players;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class PlayerQueries
{
    public function listing(): Builder
    {
        return DB::table('players as p')->leftJoin('player_profiles as i', 'i.player_id', '=', 'p.id')
            ->leftJoin('admin_player_verifications as v', 'v.player_id', '=', 'p.id')
            ->leftJoin('nationalities as n', 'n.id', '=', 'i.nationality_id')
            ->leftJoin('income_sources as income', 'income.id', '=', 'i.income_source_id')
            ->leftJoin('work_types as work', 'work.id', '=', 'i.work_type_id')
            ->leftJoin('business_types as business', 'business.id', '=', 'i.business_type_id')
            ->select([
                'p.id', 'p.name', 'p.mobile_number', 'p.email as email_address', 'p.status as active_status',
                'p.created_at', 'i.first_name', 'i.middle_name', 'i.last_name', 'i.marital_status',
                'i.birthdate', 'i.address', 'i.kyc_status as account_verified', 'n.name as nationality',
                'income.name as source_of_income', 'work.name as nature_of_work', 'business.name as nature_of_business', 'v.verified_at',
            ]);
    }

    /** @param array<string, mixed> $filters */
    public function filtered(array $filters): Builder
    {
        $query = $this->listing();
        if (! empty($filters['search'])) {
            $search = '%'.$filters['search'].'%';
            $mobile = preg_match('/\A[+0-9][0-9\s-]*\z/', $filters['search'])
                ? preg_replace('/^(?:\+63|63|0)/', '', preg_replace('/[\s-]/', '', $filters['search'])) : $filters['search'];
            $mobile = $mobile === '' ? $filters['search'] : $mobile;
            $query->where(fn (Builder $q) => $q->where('p.name', 'like', $search)->orWhere('p.mobile_number', 'like', '%'.$mobile.'%')
                ->orWhere('p.email', 'like', $search)->orWhere('p.username', 'like', $search));
        }
        if (($filters['status'] ?? 'All') !== 'All') {
            $query->where('p.status', $filters['status']);
        }
        if (($filters['account_verified'] ?? 'All') !== 'All') {
            $query->where('i.kyc_status', $filters['account_verified']);
        }
        if (($filters['type'] ?? 'Players') === 'Duplicate Players') {
            $query->whereNotNull('i.birthdate')->where('i.first_name', '<>', '')->where('i.last_name', '<>', '')
                ->whereExists(fn (Builder $q) => $q->selectRaw('1')->from('player_profiles as dup')
                    ->whereColumn('dup.first_name', 'i.first_name')->whereColumn('dup.last_name', 'i.last_name')
                    ->whereColumn('dup.birthdate', 'i.birthdate')->whereColumn('dup.player_id', '<>', 'i.player_id'));
        }
        if (! empty($filters['duplicate_of'])) {
            $reference = DB::table('player_profiles')->where('player_id', $filters['duplicate_of'])->first(['first_name', 'last_name', 'birthdate']);
            abort_unless($reference !== null, 404);
            $query->where('i.first_name', $reference->first_name)->where('i.last_name', $reference->last_name)
                ->where('i.birthdate', $reference->birthdate)->whereNotNull('i.birthdate')
                ->where('i.first_name', '<>', '')->where('i.last_name', '<>', '')->where('p.id', '<>', $filters['duplicate_of']);
        }
        $this->dates($query, $filters, 'p.created_at');
        $sort = ['id' => 'p.id', 'name' => 'p.name', 'account_verified' => 'i.kyc_status', 'verified_at' => 'v.verified_at', 'created_at' => 'p.created_at'];

        return $query->orderBy($sort[$filters['sort'] ?? 'id'], $filters['direction'] ?? 'desc')->orderByDesc('p.id');
    }

    /** @param array<string, mixed> $filters */
    public function withdrawalAccounts(array $filters): Builder
    {
        $query = DB::table('admin_player_fund_accounts as f')
            ->leftJoin('admin_player_banks as b', 'b.id', '=', 'f.bank_id')
            ->leftJoin('players as p', 'p.id', '=', 'f.player_id')
            ->select(['f.id', 'p.id as player_id', 'b.bank', 'f.account_number', 'f.account_name', 'f.is_verified', 'f.created_at']);

        if (isset($filters['search']) && $filters['search'] !== '') {
            $search = '%'.$filters['search'].'%';
            $query->where(fn (Builder $q) => $q->where('b.bank', 'like', $search)
                ->orWhere('f.account_number', 'like', $search)->orWhere('f.account_name', 'like', $search)
                ->orWhere('f.is_verified', 'like', $search));
        }
        $this->dates($query, $filters, 'f.created_at');
        $sort = ['bank' => 'b.bank', 'account_number' => 'f.account_number', 'account_name' => 'f.account_name',
            'is_verified' => 'f.is_verified', 'created_at' => 'f.created_at'];

        return $query->orderBy($sort[$filters['sort'] ?? 'created_at'], $filters['direction'] ?? 'desc')->orderByDesc('f.id');
    }

    /** @return array<string, mixed> */
    public function details(int $playerId): array
    {
        $record = $this->listing()->where('p.id', $playerId)
            ->leftJoin('barangays as permanent', 'permanent.id', '=', 'i.barangay_id')
            ->leftJoin('cities as permanent_city', 'permanent_city.id', '=', 'permanent.city_id')
            ->leftJoin('barangays as current', 'current.id', '=', 'i.current_barangay_id')
            ->leftJoin('cities as current_city', 'current_city.id', '=', 'current.city_id')
            ->leftJoin('gaming_sites as site', 'site.id', '=', 'i.gaming_site_id')
            ->leftJoin('other_income_types as other_income', 'other_income.id', '=', 'i.other_income_type_id')
            ->leftJoin('player_wallet as wallet', 'wallet.player_id', '=', 'p.id')
            ->addSelect([
                'p.betting', 'p.cashin', 'p.cashout', 'i.gender', 'i.birthplace', 'i.current_address',
                'i.occupation', 'site.name as gaming_venue', 'other_income.name as others_income',
                'permanent.name as permanent_barangay', 'permanent_city.name as permanent_city_mun',
                'current.name as current_barangay', 'current_city.name as current_city_mun', 'wallet.actual_amount as wallet',
            ])->first();
        abort_unless($record !== null, 404);
        $data = (array) $record;
        $data['age'] = $record->birthdate ? (int) CarbonImmutable::parse($record->birthdate, 'Asia/Manila')->diffInYears(CarbonImmutable::now('Asia/Manila')) : null;
        $profile = DB::table('player_profiles')->where('player_id', $playerId)->first(['selfie_path', 'valid_id_path']);
        foreach (['selfie', 'valid_id'] as $kind) {
            $data[$kind] = ! empty($profile->{$kind.'_path'}) ? route('players.image', ['player' => $playerId, 'kind' => $kind]) : null;
        }
        $data['admin_deactivate_reason'] = $this->lastReason($playerId, 'player_account');
        $data['player_deactivate_reason'] = DB::table('admin_player_logs')->where('player_id', $playerId)
            ->where('action', 'deactivate_player_account')->orderByDesc('id')->value('details');
        $data['rejected_reason'] = $record->account_verified === 'Verified' ? null : $this->lastReason($playerId, 'rejected_player');

        return $data;
    }

    /** @param array<string, mixed> $filters */
    public function activity(int $playerId, array $filters): Builder
    {
        if (($filters['tab'] ?? 'transactions') === 'transactions') {
            $query = DB::table('player_wallet_transactions as t')->leftJoin('player_wallet_transaction_types as type', 'type.id', '=', 't.transaction_type_id')
                ->where('t.player_id', $playerId)->select(['t.id', 't.description as details', 't.amount', 't.created_at', 'type.wallet_transaction_type as transaction_type']);
            $this->dates($query, $filters, 't.created_at', 'activity_');
            if (! empty($filters['activity_search'])) {
                $query->where(fn (Builder $q) => $q->where('t.description', 'like', '%'.$filters['activity_search'].'%')
                    ->orWhere('type.wallet_transaction_type', 'like', '%'.$filters['activity_search'].'%'));
            }

            return $query->orderByDesc('t.created_at')->orderByDesc('t.id');
        }
        if ($filters['tab'] === 'player_logs') {
            $query = DB::table('admin_player_logs')->where('player_id', $playerId)->select(['id', 'action', 'details', 'created_at']);
        } else {
            $legacy = DB::table('admin_player_legacy_logs as l')->leftJoin('users as u', 'u.id', '=', 'l.user_id')
                ->where('l.player_id', $playerId)->whereNotIn('l.action', ['verified_cashin', 'approved_cashin'])
                ->select(['l.id', 'l.action', 'l.details', 'l.created_at', 'u.name as admin'])->selectRaw("'legacy' as origin");
            $native = DB::table('admin_player_audits as l')->leftJoin('users as u', 'u.id', '=', 'l.user_id')
                ->where('l.player_id', $playerId)->select(['l.id', 'l.action', 'l.details', 'l.created_at', 'u.name as admin'])->selectRaw("'current' as origin");
            $query = DB::query()->fromSub($legacy->unionAll($native), 'logs');
        }
        $this->dates($query, $filters, 'created_at', 'activity_');
        if (! empty($filters['activity_search'])) {
            $query->where(fn (Builder $q) => $q->where('details', 'like', '%'.$filters['activity_search'].'%')->orWhere('action', 'like', '%'.$filters['activity_search'].'%'));
        }

        return $query->orderByDesc('created_at')->orderByDesc('id');
    }

    /** @param array<string, mixed> $filters */
    private function dates(Builder $query, array $filters, string $column, string $prefix = ''): void
    {
        if (! empty($filters[$prefix.'date_from'])) {
            $query->where($column, '>=', CarbonImmutable::parse($filters[$prefix.'date_from'], 'Asia/Manila')->startOfDay()->utc());
        }
        if (! empty($filters[$prefix.'date_to'])) {
            $query->where($column, '<', CarbonImmutable::parse($filters[$prefix.'date_to'], 'Asia/Manila')->addDay()->startOfDay()->utc());
        }
    }

    private function lastReason(int $playerId, string $action): ?string
    {
        $native = DB::table('admin_player_audits')->where('player_id', $playerId)->where('action', $action)->orderByDesc('id')->value('details');

        return $native ?? DB::table('admin_player_legacy_logs')->where('player_id', $playerId)->where('action', $action)->orderByDesc('id')->value('details');
    }
}
