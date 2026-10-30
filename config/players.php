<?php

return [
    'legacy_source' => env('LEGACY_SOURCE', 'gamexsports-local'),
    'media_root' => env('PLAYER_MEDIA_ROOT', ''),
    'cashier_approval_admin_id' => (int) env('CASHIER_APPROVAL_ADMIN_ID', 47),
    'pagcor' => [
        'base_url' => env('PAGCOR_BASE_URL', ''),
        'aes_key' => env('PAGCOR_AES_KEY', ''),
        'member_id' => env('PAGCOR_SIGN_KEY', ''),
    ],
];
