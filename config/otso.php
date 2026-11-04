<?php

return [
    'legacy_source' => env('LEGACY_OTSO_SOURCE', env('LEGACY_SOURCE', 'gamexsports-local')),
    'analytics' => [
        'connection' => env('OTSO_ANALYTICS_CONNECTION', 'otso_analytics'),
        'campaign' => 'otso_panalo_login_v1',
        'bet_timezone' => env('OTSO_ANALYTICS_BET_TIMEZONE', 'Asia/Manila'),
    ],
];
