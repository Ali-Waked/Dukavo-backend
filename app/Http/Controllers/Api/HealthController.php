<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\HealthResource;

class HealthController extends Controller
{
    /**
     * Report the API status so the frontend can verify its connection.
     */
    public function __invoke(): HealthResource
    {
        return new HealthResource([
            'status' => 'ok',
            'application' => config('app.name'),
            'laravel' => app()->version(),
            'php' => PHP_VERSION,
            'environment' => app()->environment(),
            'time' => now()->toIso8601String(),
        ]);
    }
}
