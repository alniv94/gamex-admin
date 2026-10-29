<?php

namespace App\Services\Ending;

use App\Models\User;

class EndingAccess
{
    public function allows(User $user, int $permission): bool
    {
        return $user->status === 'Active' && $user->permissions()->where('admin_permissions.id', $permission)->exists();
    }

    public function require(User $user, int $permission): void
    {
        abort_unless($this->allows($user, $permission), 403, 'You do not have permission for this ending game action.');
    }
}
