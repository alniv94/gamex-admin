<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // OTP replaces the starter's password login and public registration endpoints.
        Fortify::ignoreRoutes();
    }
}
