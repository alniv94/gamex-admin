<?php

namespace App\Services\Auth;

use App\Models\AdminProfile;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class AdminUserManagement
{
    /** @param array<string, mixed> $data */
    public function saveUser(Request $request, array $data, ?int $id = null): void
    {
        $this->mutate($request, function () use ($request, $data, $id): void {
            Validator::make($data, [
                'mobile_number' => [Rule::unique('users')->ignore($id)],
                'email' => ['nullable', Rule::unique('users')->ignore($id)],
            ])->validate();
            $user = $id === null ? new User : User::query()->whereHas('profile')->lockForUpdate()->findOrFail($id);
            $before = $user->exists ? $this->userData($user) : null;
            if ($user->exists) {
                $this->checkVersion($user->profile->management_version, (int) $data['version']);
            }
            $user->fill(['name' => $data['name'], 'mobile_number' => $data['mobile_number'], 'email' => $data['email'] ?? null]);
            $mobileChanged = $user->exists && $user->isDirty('mobile_number');
            if ($user->isDirty('email')) {
                $user->email_verified_at = null;
            }
            if (! $user->exists) {
                $user->status = 'Active';
                $user->password = Str::random(64);
            }
            $user->save();
            if ($id === null) {
                $user->profile()->create([]);
            }
            $roles = Role::query()->where('guard_name', 'web')->whereIn('id', $data['roles'])->get();
            $user->syncRoles($roles);
            $user->syncPermissions($data['permissions']);
            $user->profile()->increment('management_version');
            if ($mobileChanged) {
                $this->revokeSessions($user);
            }
            $this->requireManager();
            $this->audit($request, 'user', $user->id, $id === null ? 'create_user' : 'update_user', $before, $this->userData($user->fresh()));
        });
    }

    public function setStatus(Request $request, int $id, string $status, int $version): void
    {
        $this->mutate($request, function () use ($request, $id, $status, $version): void {
            $user = User::query()->whereHas('profile')->lockForUpdate()->findOrFail($id);
            $this->checkVersion($user->profile->management_version, $version);
            if ($user->id === $request->user()->id && $status === 'Inactive') {
                throw ValidationException::withMessages(['status' => 'You cannot deactivate your own account.']);
            }
            if ($status === 'Active' && MobileNumber::normalize((string) $user->mobile_number) === null) {
                throw ValidationException::withMessages(['status' => 'Set a valid mobile number before activating this account.']);
            }
            $before = $this->userData($user);
            $user->update(['status' => $status]);
            $user->profile()->increment('management_version');
            $this->revokeSessions($user);
            $this->requireManager();
            $this->audit($request, 'user', $id, $status === 'Active' ? 'activate_user' : 'deactivate_user', $before, $this->userData($user->fresh()));
        });
    }

    /** @param array<string, mixed> $data */
    public function saveRole(Request $request, array $data, ?int $id = null): void
    {
        $this->mutate($request, function () use ($request, $data, $id): void {
            Validator::make($data, ['name' => [Rule::unique('admin_roles')->where('guard_name', 'web')->ignore($id)]])->validate();
            $role = $id === null ? new Role(['guard_name' => 'web']) : Role::query()->where('guard_name', 'web')->lockForUpdate()->findOrFail($id);
            $before = $role->exists ? $this->roleData($role) : null;
            if ($role->exists) {
                $this->checkVersion((int) $role->getAttribute('management_version'), (int) $data['version']);
            }
            $role->name = $data['name'];
            $role->setAttribute('management_version', (int) $role->getAttribute('management_version') + 1);
            $role->save();
            $role->syncPermissions($data['permissions']);
            $userIds = DB::table('admin_model_roles')->where('role_id', $role->id)->where('model_type', (new User)->getMorphClass())->pluck('model_id');
            AdminProfile::query()->whereIn('user_id', $userIds)->increment('management_version');
            $this->requireManager();
            $this->audit($request, 'role', (int) $role->id, $id === null ? 'create_role' : 'update_role', $before, $this->roleData($role->fresh()));
        });
    }

    /** @return array<string, mixed> */
    public function userData(User $user): array
    {
        return [
            'id' => $user->id, 'name' => $user->name, 'mobile_number' => $user->mobile_number,
            'email' => $user->email, 'status' => $user->status,
            'version' => $user->profile->management_version,
            'roles' => $user->roles->pluck('id')->sort()->values()->all(),
            'direct_permissions' => $user->permissions->pluck('name')->sort()->values()->all(),
            'inherited_permissions' => $user->getPermissionsViaRoles()->pluck('name')->unique()->sort()->values()->all(),
            'effective_permissions' => $user->getAllPermissions()->pluck('name')->sort()->values()->all(),
        ];
    }

    /** @return array<string, mixed> */
    public function roleData(Role $role): array
    {
        return ['id' => $role->id, 'name' => $role->name, 'version' => (int) $role->getAttribute('management_version'),
            'permissions' => $role->permissions->pluck('name')->sort()->values()->all()];
    }

    private function mutate(Request $request, Closure $operation): void
    {
        try {
            DB::transaction(function () use ($request, $operation): void {
                // A single lock serializes access edits, including the last-manager check.
                Permission::query()->where('guard_name', 'web')->where('name', AdminPermissions::MANAGE_USERS)->lockForUpdate()->firstOrFail();
                app(PermissionRegistrar::class)->forgetCachedPermissions();
                $actor = User::query()->with('profile')->findOrFail($request->user()->id);
                Gate::forUser($actor)->authorize(AdminPermissions::MANAGE_USERS);
                abort_unless($actor->profile->session_version === $request->session()->get('admin_version'), 403);
                $operation();
            }, 3);
        } finally {
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }

    private function checkVersion(int $actual, int $expected): void
    {
        if ($actual !== $expected) {
            throw ValidationException::withMessages(['version' => 'This record changed since you opened it. Close this form, refresh the page, and try again.']);
        }
    }

    private function requireManager(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $exists = User::query()->where('status', 'Active')->whereHas('profile')->permission(AdminPermissions::MANAGE_USERS)->exists();
        if (! $exists) {
            throw ValidationException::withMessages(['permissions' => 'Keep at least one active user with the Manage users and roles permission.']);
        }
    }

    private function revokeSessions(User $user): void
    {
        $user->profile()->increment('session_version');
        DB::table('admin_otp_challenges')->where('user_id', $user->id)->whereNull('consumed_at')->update(['consumed_at' => now()]);
    }

    /** @param array<string, mixed>|null $before
     * @param  array<string, mixed>  $after
     */
    private function audit(Request $request, string $type, int $id, string $action, ?array $before, array $after): void
    {
        DB::table('admin_user_management_logs')->insert([
            'actor_id' => $request->user()->id, 'action' => $action, 'subject_type' => $type, 'subject_id' => $id,
            'before' => $before === null ? null : json_encode($before, JSON_THROW_ON_ERROR),
            'after' => json_encode($after, JSON_THROW_ON_ERROR), 'ip_address' => $request->ip(), 'created_at' => now(),
        ]);
    }
}
