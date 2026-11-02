<?php

namespace App\Services\Investors;

use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class InvestorWorkflow
{
    public function __construct(private InvestorQueries $queries) {}

    public function ready(): void
    {
        if (! DB::table('admin_investor_imports')->where('source', config('players.legacy_source'))->exists()) {
            throw ValidationException::withMessages(['investor' => 'Complete and verify the investor import before making changes.']);
        }
    }

    /** @template T
     * @param  Closure(): T  $operation
     * @return T
     */
    private function mutate(Closure $operation): mixed
    {
        $this->ready();
        $lock = Cache::lock('investors:import', 120);
        if (! $lock->get()) {
            throw ValidationException::withMessages(['investor' => 'Investor records are being updated. Please try again shortly.']);
        }
        try {
            return DB::transaction($operation);
        } finally {
            $lock->release();
        }
    }

    /** @param array<string, mixed> $data */
    public function save(?int $investor, array $data, int $admin): int
    {
        return $this->mutate(function () use ($investor, $data, $admin): int {
            $old = $investor ? DB::table('admin_investors')->where('id', $investor)->lockForUpdate()->first() : null;
            abort_if($investor && ! $old, 404);
            if ($old && $old->link_type === 'New' && $data['link_type'] !== 'New') {
                throw ValidationException::withMessages(['link_type' => 'An account using new referral links cannot return to old links.']);
            }
            if (! empty($data['password'])) {
                $data['password'] = Hash::make($data['password']);
            } else {
                unset($data['password']);
            }
            if ($investor) {
                unset($data['username'], $data['wallet']);
                DB::table('admin_investors')->where('id', $investor)->update($data);
                $ids = $this->queries->hierarchy($investor);
                DB::table('admin_investors')->whereIn('id', $ids)->update(['territory' => $data['territory'], 'link_type' => $data['link_type']]);
            } else {
                $investor = DB::table('admin_investors')->insertGetId($data + ['investor_type_id' => 1, 'created_at' => now()]);
            }
            if ($data['link_type'] === 'New') {
                $this->ensureReferrals($investor);
            }
            $this->audit($admin, $investor, $old ? 'update_account' : 'create_account', [
                'fields' => array_values(array_diff(array_keys($data), ['password'])), 'password_changed' => isset($data['password']),
            ]);

            return $investor;
        });
    }

    private function ensureReferrals(int $investor): void
    {
        $accounts = DB::table('admin_investors')->whereIn('id', $this->queries->hierarchy($investor))->get(['id', 'investor_type_id']);
        foreach ($accounts as $account) {
            $types = match ((int) $account->investor_type_id) {
                1 => ['SM', 'RP'], 2 => ['SS', 'SR', 'RP'], default => ['SR', 'RP']
            };
            foreach ($types as $type) {
                if (! DB::table('admin_investor_referral_links')->where('investor_id', $account->id)->where('link_type', $type)->exists()) {
                    DB::table('admin_investor_referral_links')->insert(['investor_id' => $account->id, 'link_type' => $type]);
                }
            }
        }
    }

    public function refreshReferrals(int $investor, int $admin): void
    {
        $this->mutate(function () use ($investor, $admin): void {
            $account = DB::table('admin_investors')->where('id', $investor)->lockForUpdate()->first();
            abort_unless($account !== null, 404);
            if ($account->link_type !== 'New' || ! $account->territory) {
                throw ValidationException::withMessages(['investor' => 'Set a territory and use New referral links first.']);
            }
            $this->ensureReferrals($investor);
            $this->audit($admin, $investor, 'refresh_referrals', []);
        });
    }

    /** @param array<string, mixed> $data */
    public function subAccount(int $investor, ?int $account, array $data, int $admin): void
    {
        $this->mutate(function () use ($investor, $account, $data, $admin): void {
            abort_unless(DB::table('admin_investors')->where('id', $investor)->exists(), 404);
            if (! empty($data['password'])) {
                $data['password'] = Hash::make($data['password']);
            } else {
                unset($data['password']);
            }
            if ($account) {
                abort_unless(DB::table('admin_investor_sub_users')->where('id', $account)->where('investor_id', $investor)->exists(), 404);
                DB::table('admin_investor_sub_users')->where('id', $account)->update($data);
            } else {
                $account = DB::table('admin_investor_sub_users')->insertGetId($data + ['investor_id' => $investor, 'created_at' => now()]);
            }
            $this->audit($admin, $investor, 'save_sub_account', ['account_id' => $account]);
        });
    }

    /** @param array<string, mixed> $filters
     * @param  array<string, mixed>  $payment
     */
    public function settle(string $kind, int $investor, array $filters, array $payment, int $admin): string
    {
        return $this->mutate(function () use ($kind, $investor, $filters, $payment, $admin): string {
            $account = DB::table('admin_investors')->where('id', $investor)->lockForUpdate()->first();
            abort_unless($account && (int) $account->investor_type_id === 1, 404);
            if ($account->status !== 'Active') {
                throw ValidationException::withMessages(['investor' => 'Only active investors can receive settlements.']);
            }
            $groups = [];
            foreach ($kind === 'payouts' ? ['base'] : ['base', 'referral'] as $ledger) {
                $groups[$ledger] = $this->queries->pending($kind, $investor, $filters, $ledger)->orderBy('id')->lockForUpdate()->get();
            }
            $cents = 0;
            foreach ($groups as $rows) {
                foreach ($rows as $row) {
                    $cents += $this->cents($kind === 'payouts' ? $row->amount : $row->commission_amount);
                }
            }
            if ($cents <= 0) {
                throw ValidationException::withMessages(['settlement' => 'There are no positive unsettled items in this date range.']);
            }
            $settlement = DB::table('admin_investor_settled_'.($kind === 'payouts' ? 'payouts' : 'commissions'))->insertGetId($payment + [
                'investor_id' => $investor, 'amount' => $this->amount($cents), 'processed_by' => $admin, 'processed_at' => now(),
            ]);
            foreach ($groups as $ledger => $rows) {
                if ($rows->isEmpty()) {
                    continue;
                }
                $query = $this->queries->pending($kind, $investor, $filters, $ledger)->whereIn('id', $rows->pluck('id'));
                $query->update($kind === 'payouts' ? ['status' => 'Approved', 'settlemet_id' => $settlement]
                    : ['is_settled' => 'Yes', 'is_paid' => 'Yes', 'settlement_id' => $settlement]);
            }
            $reference = ($kind === 'payouts' ? 'PS' : 'CS').str_pad((string) $settlement, 8, '0', STR_PAD_LEFT);
            $this->audit($admin, $investor, 'settle_'.$kind, [
                'settlement' => $reference, 'amount' => $this->amount($cents), 'filters' => $filters,
                'items' => collect($groups)->map(fn ($rows) => $rows->pluck('id')->all())->all(),
            ]);

            return $reference;
        });
    }

    /** @param array<string, mixed> $payment */
    public function special(int $settlement, string $action, array $payment, int $admin): void
    {
        $this->mutate(function () use ($settlement, $action, $payment, $admin): void {
            $row = DB::table('admin_investor_special_settlement')->where('id', $settlement)->lockForUpdate()->first();
            abort_unless($row !== null, 404);
            $expected = $action === 'verify' ? 'Requested' : 'Verified';
            $next = $action === 'verify' ? 'Verified' : 'Approved';
            if ($row->status !== $expected) {
                throw ValidationException::withMessages(['settlement' => 'This settlement must be '.$expected.' before this action.']);
            }
            $items = DB::table('admin_investor_commissions')->where('special_settlement_id', $settlement)->orderBy('id')->lockForUpdate()->get();
            $cents = 0;
            foreach ($items as $item) {
                if ($item->is_settled === 'Yes' || $item->settlement_id !== null) {
                    throw ValidationException::withMessages(['settlement' => 'This request contains commissions that were already settled.']);
                }
                $cents += $this->cents($item->commission_amount);
            }
            if ($cents <= 0 || $cents !== $this->cents($row->amount)) {
                throw ValidationException::withMessages(['settlement' => 'The request amount does not match its commission items. Review it before proceeding.']);
            }
            DB::table('admin_investor_special_settlement')->where('id', $settlement)->update($payment + ['status' => $next]);
            if ($action === 'approve') {
                DB::table('admin_investor_commissions')->where('special_settlement_id', $settlement)->update(['is_settled' => 'Yes', 'is_paid' => 'Yes']);
            }
            DB::table('admin_investor_special_settlement_logs')->insert([
                'special_settlement_id' => $settlement, 'status' => $next, 'type' => 'Admin', 'user_id' => $admin, 'process_date' => now(),
            ]);
            $this->audit($admin, $row->investor_id, 'special_'.$action, ['settlement_id' => $settlement, 'amount' => $row->amount]);
        });
    }

    public function cents(string|int|float|null $amount): int
    {
        $value = (string) ($amount ?? '0');
        $negative = str_starts_with($value, '-');
        $parts = explode('.', ltrim($value, '-'));

        return ((int) $parts[0] * 100 + (int) str_pad(substr($parts[1] ?? '', 0, 2), 2, '0')) * ($negative ? -1 : 1);
    }

    public function amount(int $cents): string
    {
        return ($cents < 0 ? '-' : '').intdiv(abs($cents), 100).'.'.str_pad((string) (abs($cents) % 100), 2, '0', STR_PAD_LEFT);
    }

    /** @param array<string, mixed> $details */
    private function audit(int $admin, ?int $investor, string $action, array $details): void
    {
        DB::table('admin_investor_audits')->insert([
            'user_id' => $admin, 'investor_id' => $investor, 'action' => $action,
            'details' => json_encode($details, JSON_THROW_ON_ERROR), 'created_at' => now(),
        ]);
    }
}
