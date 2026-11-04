<?php

return [
    'otp_lifetime' => 300,
    'otp_max_attempts' => 5,
    'resend_interval' => 60,
    'player_sms' => [
        'gateway' => env('PLAYER_SMS_GATEWAY', 'smtp3@m360.com.ph'),
        'subject' => env('PLAYER_SMS_SUBJECT', 'GAMEXSPORTS'),
        'from_address' => env('PLAYER_SMS_FROM_ADDRESS', env('PLAYER_SMS_USERNAME')),
        'from_name' => env('PLAYER_SMS_FROM_NAME', 'Las Casas Gaming Administrator'),
    ],
    'sms' => [
        'driver' => env('ADMIN_SMS_DRIVER', 'm360'),
        'app_key' => env('M360_APP_KEY'),
        'app_secret' => env('M360_APP_SECRET'),
        'mask' => env('M360_MASK', 'GameXSports'),
    ],
];
