<?php

use App\Fortify\FortifyResponseServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\FortifyServiceProvider;

return [
    AppServiceProvider::class,
    FortifyServiceProvider::class,
    FortifyResponseServiceProvider::class,
];
