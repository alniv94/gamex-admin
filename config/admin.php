<?php

return [
    'otp_lifetime' => 300,
    'otp_max_attempts' => 5,
    'resend_interval' => 60,
    'sms' => [
        'driver' => env('ADMIN_SMS_DRIVER', 'm360'),
        'app_key' => env('M360_APP_KEY'),
        'app_secret' => env('M360_APP_SECRET'),
        'mask' => env('M360_MASK', 'GameXSports'),
    ],
];
