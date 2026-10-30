<?php

return [
    'legacy_source' => env('LEGACY_SOURCE', 'gamexsports-local'),
    'media_root' => env('PLAYER_MEDIA_ROOT', ''),
    'pagcor' => [
        'base_url' => env('PAGCOR_BASE_URL', ''),
        'aes_key' => env('PAGCOR_AES_KEY', ''),
        'member_id' => env('PAGCOR_SIGN_KEY', ''),
    ],
];
