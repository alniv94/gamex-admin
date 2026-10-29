<?php

use App\Providers\AppServiceProvider;
use App\Providers\DatabaseSafetyServiceProvider;
use App\Providers\FortifyServiceProvider;

return [
    DatabaseSafetyServiceProvider::class,
    AppServiceProvider::class,
    FortifyServiceProvider::class,
];
