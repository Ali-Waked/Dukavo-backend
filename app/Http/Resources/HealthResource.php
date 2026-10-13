<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property string $status
 * @property string $application
 * @property string $laravel
 * @property string $php
 * @property string $environment
 * @property string $time
 */
class HealthResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'status' => $this->resource['status'],
            'application' => $this->resource['application'],
            'laravel' => $this->resource['laravel'],
            'php' => $this->resource['php'],
            'environment' => $this->resource['environment'],
            'time' => $this->resource['time'],
        ];
    }
}
