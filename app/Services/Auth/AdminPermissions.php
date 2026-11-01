<?php

namespace App\Services\Auth;

class AdminPermissions
{
    public const VIEW_USERS = 'Users';

    public const MANAGE_USERS = 'Manage users and roles';

    public static function cashier(string $kind, string $action): string
    {
        return match ($kind.'.'.$action) {
            'cashin.view' => 'Cash In',
            'cashout.view' => 'Cash Out',
            'cashin.verify', 'cashin.reject' => 'Cashin Verify',
            'cashout.verify', 'cashout.reject' => 'Cashout Verify',
            'cashin.approve' => 'Cash In Approve',
            'cashout.approve' => 'Cash Out Approve',
            'cashin.disapprove' => 'Cash In Dispprove',
            'cashout.disapprove' => 'Cash Out Dispprove',
            'cashin.manual' => 'Cash In Manual',
            'cashout.manual' => 'Cash Out Manual',
            default => throw new \InvalidArgumentException('Unknown cashier capability.'),
        };
    }
}
