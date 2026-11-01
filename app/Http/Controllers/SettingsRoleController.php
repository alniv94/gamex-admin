<?php

namespace App\Http\Controllers;

use App\Http\Requests\SaveAdminRoleRequest;
use App\Services\Auth\AdminPermissions;
use App\Services\Auth\AdminUserManagement;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class SettingsRoleController extends Controller
{
    public function store(SaveAdminRoleRequest $request, AdminUserManagement $management): RedirectResponse
    {
        $management->saveRole($request, $request->validated());

        return $this->success($request);
    }

    public function update(SaveAdminRoleRequest $request, int $role, AdminUserManagement $management): RedirectResponse
    {
        $management->saveRole($request, $request->validated(), $role);

        return $this->success($request);
    }

    private function success(SaveAdminRoleRequest $request): RedirectResponse
    {
        Inertia::flash('success', 'Role saved. Its permissions apply to every assigned user.');

        return $request->user()->fresh()->canAny([AdminPermissions::VIEW_USERS, AdminPermissions::MANAGE_USERS])
            ? back(303) : to_route('dashboard', [], 303);
    }
}
