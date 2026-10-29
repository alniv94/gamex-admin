<?php

namespace App\Services\Auth;

class MobileNumber
{
    public static function normalize(string $value): ?string
    {
        $value = preg_replace('/[\\s()-]/', '', trim($value));
        if (preg_match('/\\A(?:\\+63|63|0)?(9[0-9]{9})\\z/', $value, $matches)) {
            return '+63'.$matches[1];
        }

        return null;
    }
}
