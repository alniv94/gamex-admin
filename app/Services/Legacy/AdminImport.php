<?php

namespace App\Services\Legacy;

use App\Models\AdminProfile;
use App\Services\Auth\MobileNumber;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class AdminImport
{
    /** @return array{accounts: array<int, array<string, mixed>>, permissions: array<int, array<string, mixed>>, warnings: int, exceptions: array<string, array<string, mixed>>, invalid_grants: int, duplicate_grants: int} */
    public function plan(): array
    {
        $source = DB::connection('legacy');
        $snapshot = $source->getDriverName() === 'mysql';
        if ($snapshot) {
            $source->unprepared('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $source->unprepared('START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY');
        }
        try {
            $users = $source->table('admin_users')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
            $profiles = $source->table('admin_user_info')->get()->keyBy('user_id');
            $catalog = $source->table('admin_access')->orderBy('id')->get();
            $access = $source->table('admin_user_access')->orderBy('access_id')->orderBy('id')->get()->groupBy('user_id');
        } finally {
            if ($snapshot) {
                $source->unprepared('ROLLBACK');
            }
        }
        $permissions = [];
        foreach ($catalog as $permission) {
            $permissions[(int) $permission->id] = [
                'id' => (int) $permission->id, 'name' => $permission->access,
                'module_order' => (int) $permission->module_order, 'module' => $permission->vue_module,
            ];
        }
        $emails = [];
        foreach ($profiles as $profile) {
            $email = strtolower(trim($profile->email_address ?? ''));
            if ($email !== '') {
                $emails[$email] = ($emails[$email] ?? 0) + 1;
            }
        }
        $accounts = [];
        $mobiles = [];
        $warnings = 0;
        $invalidGrants = 0;
        $duplicateGrants = 0;
        foreach ($users as $user) {
            $id = (int) $user['id'];
            $profile = isset($profiles[$id]) ? (array) $profiles[$id] : null;
            $mobile = MobileNumber::normalize((string) $user['mobile_number']);
            $inactiveWithoutMobile = $user['is_active'] === 'Inactive' && trim((string) $user['mobile_number']) === '';
            if (! $profile || (! $mobile && ! $inactiveWithoutMobile) || $id < 1 || ! in_array($user['is_active'], ['Active', 'Inactive'], true)) {
                throw new RuntimeException("Legacy administrator {$id} has an invalid identity, status, or missing profile.");
            }
            if ($mobile !== null && isset($mobiles[$mobile])) {
                throw new RuntimeException("Legacy administrator {$id} has a duplicate normalized mobile number.");
            }
            if ($mobile !== null) {
                $mobiles[$mobile] = true;
            }
            $rows = ($access[$id] ?? collect())->map(fn ($row) => (array) $row)->values()->all();
            $rawIds = array_map(fn ($row) => (int) $row['access_id'], $rows);
            $ids = array_values(array_unique(array_intersect($rawIds, array_keys($permissions))));
            sort($ids);
            $invalidGrants += count(array_diff($rawIds, array_keys($permissions)));
            $duplicateGrants += count($rawIds) - count(array_unique($rawIds));
            $email = strtolower(trim($profile['email_address'] ?? ''));
            if ($email !== '' && (! filter_var($email, FILTER_VALIDATE_EMAIL) || $emails[$email] !== 1)) {
                $warnings++;
                $email = '';
            }
            $created = $user['created_at'] ?? null;
            $created = $created && ! str_starts_with($created, '0000-') ?
                CarbonImmutable::parse($created, 'Asia/Manila')->utc()->format('Y-m-d H:i:s') : null;
            $birthday = $profile['birthdate'] ?? null;
            $birthday = $birthday && ! str_starts_with($birthday, '0000-') ? $birthday : null;
            // Session keys and login-verification state are intentionally excluded.
            unset($user['login_key'], $user['login_verified']);
            $archive = ['user' => $user, 'profile' => $profile, 'permission_rows' => $rows];
            $accounts[$id] = [
                'user' => [
                    'id' => $id,
                    'name' => trim(implode(' ', array_filter([$profile['first_name'], $profile['middle_name'], $profile['last_name']]))),
                    'mobile_number' => $mobile, 'email' => $email === '' ? null : $email,
                    'password' => $user['password'] ?? '!', 'status' => $user['is_active'],
                    'created_at' => $created, 'updated_at' => $created,
                ],
                'profile' => [
                    'user_id' => $id, 'legacy_admin_id' => $id,
                    'first_name' => $profile['first_name'], 'middle_name' => $profile['middle_name'],
                    'last_name' => $profile['last_name'], 'email_address' => $profile['email_address'],
                    'birthdate' => $birthday, 'legacy_role_id' => $profile['role_id'],
                ],
                'archive' => $archive,
                'checksum' => hash('sha256', json_encode($archive, JSON_THROW_ON_ERROR)),
                'permissions' => $ids,
            ];
        }
        // Preserve orphaned rows as encrypted evidence without granting access to a nonexistent user.
        $exceptions = [];
        foreach ($access as $userId => $rows) {
            if (! isset($accounts[$userId])) {
                foreach ($rows as $row) {
                    $exceptions['admin_user_access:'.$row->id] = (array) $row;
                }
            }
        }
        foreach ($profiles as $userId => $row) {
            if (! isset($accounts[$userId])) {
                $exceptions['admin_user_info:'.$userId] = (array) $row;
            }
        }

        return ['accounts' => $accounts, 'permissions' => $permissions, 'warnings' => $warnings,
            'exceptions' => $exceptions, 'invalid_grants' => $invalidGrants, 'duplicate_grants' => $duplicateGrants];
    }

    /** @param array{accounts: array<int, array<string, mixed>>, permissions: array<int, array<string, mixed>>, warnings: int, exceptions: array<string, array<string, mixed>>, invalid_grants: int, duplicate_grants: int} $plan
     * @return array{new: int, existing: int, active: int, inactive: int, permissions: int, assignments: int, email_warnings: int, invalid_grants_archived: int, duplicate_grants_archived: int, orphan_rows_archived: int, pending_catalog: int, pending_archives: int}
     */
    public function inspect(array $plan): array
    {
        $new = 0;
        $existing = 0;
        $active = 0;
        $assignments = 0;
        $pendingCatalog = 0;
        $pendingArchives = 0;
        foreach ($plan['accounts'] as $id => $account) {
            $mapped = DB::table('admin_profiles')->where('legacy_admin_id', $id)->first();
            if ($mapped) {
                if ($mapped->user_id !== $id && (int) $mapped->user_id !== $id) {
                    throw new RuntimeException("Administrator {$id} has a conflicting target mapping.");
                }
                $this->verifyAccount($id, $account);
                $existing++;
            } else {
                $query = DB::table('users')->where('id', $id);
                if ($account['user']['mobile_number'] !== null) {
                    $query->orWhere('mobile_number', $account['user']['mobile_number']);
                }
                if ($account['user']['email']) {
                    $query->orWhere('email', $account['user']['email']);
                }
                if ($query->exists()) {
                    throw new RuntimeException("Administrator {$id} conflicts with an existing target identity. No accounts were overwritten.");
                }
                $new++;
            }
            $active += $account['user']['status'] === 'Active' ? 1 : 0;
            $assignments += count($account['permissions']);
        }
        foreach ($plan['permissions'] as $id => $permission) {
            $target = DB::table('admin_permissions')->where('id', $id)->first();
            $pendingCatalog += $target ? 0 : 1;
            if ($target && (array) $target != $permission) {
                throw new RuntimeException("Permission {$id} differs from the source. Review the change before importing.");
            }
        }
        foreach ($plan['exceptions'] as $key => $row) {
            $target = DB::table('admin_legacy_exceptions')->where('id', $key)->first();
            if (! $target) {
                $pendingArchives++;
            } elseif ($target->source_checksum !== hash('sha256', json_encode($row, JSON_THROW_ON_ERROR))
                || json_decode(Crypt::decryptString($target->payload), true, flags: JSON_THROW_ON_ERROR) !== $row) {
                throw new RuntimeException('A legacy exception archive differs from its source.');
            }
        }

        return [
            'new' => $new, 'existing' => $existing, 'active' => $active,
            'inactive' => count($plan['accounts']) - $active, 'permissions' => count($plan['permissions']),
            'assignments' => $assignments, 'email_warnings' => $plan['warnings'],
            'invalid_grants_archived' => $plan['invalid_grants'], 'duplicate_grants_archived' => $plan['duplicate_grants'],
            'orphan_rows_archived' => count($plan['exceptions']),
            'pending_catalog' => $pendingCatalog, 'pending_archives' => $pendingArchives,
        ];
    }

    /** @param array{accounts: array<int, array<string, mixed>>, permissions: array<int, array<string, mixed>>, warnings: int, exceptions: array<string, array<string, mixed>>, invalid_grants: int, duplicate_grants: int} $plan */
    public function execute(array $plan): void
    {
        DB::transaction(function () use ($plan): void {
            $this->inspect($plan);
            foreach ($plan['exceptions'] as $key => $row) {
                if (! DB::table('admin_legacy_exceptions')->where('id', $key)->exists()) {
                    $payload = json_encode($row, JSON_THROW_ON_ERROR);
                    DB::table('admin_legacy_exceptions')->insert([
                        'id' => $key, 'reason' => 'Referenced administrator does not exist',
                        'source_checksum' => hash('sha256', $payload),
                        'payload' => Crypt::encryptString($payload), 'created_at' => now(),
                    ]);
                }
            }
            foreach ($plan['permissions'] as $permission) {
                if (! DB::table('admin_permissions')->where('id', $permission['id'])->exists()) {
                    DB::table('admin_permissions')->insert($permission);
                }
            }
            foreach ($plan['accounts'] as $id => $account) {
                if (DB::table('admin_profiles')->where('legacy_admin_id', $id)->exists()) {
                    continue;
                }
                // Query builder preserves the legacy hash exactly; no password mutator or rehash.
                DB::table('users')->insert($account['user']);
                DB::table('admin_profiles')->insert([
                    ...$account['profile'],
                    'legacy_payload' => Crypt::encryptString(json_encode($account['archive'], JSON_THROW_ON_ERROR)),
                    'source_checksum' => $account['checksum'], 'created_at' => now(), 'updated_at' => now(),
                ]);
                foreach ($account['permissions'] as $permission) {
                    DB::table('admin_user_permissions')->insert(['user_id' => $id, 'permission_id' => $permission]);
                }
            }
            $verified = $this->inspect($plan);
            if ($verified['new'] || $verified['pending_catalog'] || $verified['pending_archives']) {
                throw new RuntimeException('Post-import verification failed. The import was rolled back.');
            }
        });
    }

    /** @param array<string, mixed> $account */
    private function verifyAccount(int $id, array $account): void
    {
        $user = DB::table('users')->where('id', $id)->first();
        $profile = AdminProfile::where('legacy_admin_id', $id)->first();
        if (! $user || ! $profile || $profile->source_checksum !== $account['checksum']
            || $profile->legacy_payload !== $account['archive']) {
            throw new RuntimeException("Administrator {$id} has changed source data or an invalid target archive.");
        }
        foreach ($account['user'] as $field => $value) {
            if ($field !== 'updated_at' && $user->{$field} != $value) {
                throw new RuntimeException("Administrator {$id} failed target field verification.");
            }
        }
        foreach ($account['profile'] as $field => $value) {
            if ($profile->getRawOriginal($field) != $value) {
                throw new RuntimeException("Administrator {$id} failed profile verification.");
            }
        }
        $actual = DB::table('admin_user_permissions')->where('user_id', $id)->orderBy('permission_id')
            ->pluck('permission_id')->map(fn ($value) => (int) $value)->all();
        if ($actual !== $account['permissions']) {
            throw new RuntimeException("Administrator {$id} failed permission verification.");
        }
    }
}
