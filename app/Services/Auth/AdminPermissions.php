<?php

namespace App\Services\Auth;

class AdminPermissions
{
    /** @var array<int, string> */
    public const LEGACY_NAMES = [
        1 => 'players.view',
        2 => 'cashin.view',
        3 => 'cashout.view',
        8 => 'ending.winners.verify',
        9 => 'ending.winners.approve',
        10 => 'ending.games.update',
        11 => 'ending.results.manage',
        12 => 'ending.cards.manage',
        17 => 'ending.games.view',
        18 => 'ending.teams.manage',
        19 => 'ending.leagues.manage',
        20 => 'ending.config.manage',
        23 => 'players.verify',
        24 => 'players.settings.update',
        26 => 'cashin.approve',
        27 => 'cashin.disapprove',
        28 => 'cashin.manual',
        29 => 'cashout.approve',
        30 => 'cashout.disapprove',
        31 => 'cashout.manual',
        32 => 'ending.games.create',
        34 => 'ending.winners.view',
        52 => 'cashin.verify',
        53 => 'cashout.verify',
    ];

    public static function legacyName(int $id): string
    {
        return self::LEGACY_NAMES[$id] ?? 'legacy.'.$id;
    }
}
