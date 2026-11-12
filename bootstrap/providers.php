<?php

use App\Fortify\FortifyResponseServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\FortifyServiceProvider;
use App\Providers\PlatformConfigurationServiceProvider;

return [
    AppServiceProvider::class,
    FortifyServiceProvider::class,
    FortifyResponseServiceProvider::class,
    PlatformConfigurationServiceProvider::class,
];
