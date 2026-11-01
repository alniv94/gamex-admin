<?php

namespace App\Http\Controllers;

use App\Http\Requests\SaveAdminUserRequest;
use App\Models\User;
use App\Services\Auth\AdminPermissions;
use App\Services\Auth\AdminUserManagement;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class SettingsUserController extends Controller
{
    public function index(Request $request, AdminUserManagement $management): Response
    {
        abort_unless($request->user()->canAny([AdminPermissions::VIEW_USERS, AdminPermissions::MANAGE_USERS]), 403);
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['Active', 'Inactive'])],
            'per_page' => ['sometimes', 'integer', Rule::in([5, 10, 25, 50, 100])],
        ]);
        $users = User::query()->whereHas('profile')->with(['profile', 'permissions', 'roles.permissions'])
            ->when($filters['search'] ?? null, fn ($query, $search) => $query->where(function ($query) use ($search): void {
                $query->where('name', 'like', '%'.$search.'%')->orWhere('mobile_number', 'like', '%'.$search.'%')->orWhere('email', 'like', '%'.$search.'%');
            }))
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->orderByDesc('id')->paginate($filters['per_page'] ?? 25)->withQueryString()
            ->through(fn (User $user) => $management->userData($user));

        return Inertia::render('settings/Users', [
            'users' => $users, 'filters' => $filters, ...$this->accessCatalog($management),
        ]);
    }

    public function create(AdminUserManagement $management): Response
    {
        Gate::authorize(AdminPermissions::MANAGE_USERS);

        return Inertia::render('settings/CreateUser', $this->accessCatalog($management));
    }

    /** @return array<string, mixed> */
    private function accessCatalog(AdminUserManagement $management): array
    {
        $permissions = Permission::query()->where('guard_name', 'web')->orderBy('name')->get()->map(function (Permission $permission): array {
            return [
                'name' => $permission->name, 'label' => $permission->name,
                'group' => $permission->getAttribute('module')
                    ? Str::headline($permission->getAttribute('module')) : 'Permissions',
            ];
        });

        return [
            'permissions' => $permissions,
            'roles' => Role::query()->where('guard_name', 'web')->with('permissions')->withCount('users')->orderBy('name')->get()
                ->map(fn (Role $role) => [...$management->roleData($role), 'users_count' => (int) $role->getAttribute('users_count')]),
        ];
    }

    public function store(SaveAdminUserRequest $request, AdminUserManagement $management): RedirectResponse
    {
        $management->saveUser($request, $request->validated());

        Inertia::flash('success', 'User created. They can sign in with a mobile OTP.');

        return to_route('settings.users.index', [], 303);
    }

    public function update(SaveAdminUserRequest $request, int $user, AdminUserManagement $management): RedirectResponse
    {
        $management->saveUser($request, $request->validated(), $user);

        return $this->success($request, 'User and access updated.');
    }

    public function status(Request $request, int $user, AdminUserManagement $management): RedirectResponse
    {
        Gate::authorize(AdminPermissions::MANAGE_USERS);
        $data = $request->validate(['status' => ['required', Rule::in(['Active', 'Inactive'])], 'version' => ['required', 'integer', 'min:0']]);
        $management->setStatus($request, $user, $data['status'], (int) $data['version']);

        return $this->success($request, $data['status'] === 'Active' ? 'User activated.' : 'User deactivated. Active sessions and pending sign-in codes have been revoked.');
    }

    private function success(Request $request, string $message): RedirectResponse
    {
        Inertia::flash('success', $message);

        return $request->user()->fresh()->canAny([AdminPermissions::VIEW_USERS, AdminPermissions::MANAGE_USERS])
            ? back(303) : to_route('dashboard', [], 303);
    }
}
