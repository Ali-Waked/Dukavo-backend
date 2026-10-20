<?php

declare(strict_types=1);

namespace App\Http\Requests\Ai;

use App\Http\Requests\ApiRequest;
use App\Services\Reporting\PlatformDashboardService;
use Illuminate\Validation\Rule;

/**
 * The window the platform briefing should cover. An omitted range falls back to
 * the same default the dashboard uses, so the two always agree.
 */
class PlatformInsightsRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'range' => ['nullable', Rule::in(PlatformDashboardService::ranges())],
        ];
    }
}
