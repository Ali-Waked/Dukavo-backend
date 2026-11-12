<?php

declare(strict_types=1);

namespace App\Http\Requests\Platform\Ai;

use App\Http\Requests\ApiRequest;

/**
 * Editing a task's platform policy.
 *
 * Limits of `0` mean "no explicit cap"; null means "inherit". The task key
 * is immutable — it is what the engine addresses tasks by, and every
 * feature maps onto it.
 */
final class UpdateAiTaskRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'label' => ['sometimes', 'string', 'max:120'],
            'enabled' => ['sometimes', 'boolean'],
            'daily_per_user' => ['sometimes', 'integer', 'min:0', 'max:1000000'],
            'daily_per_tenant' => ['sometimes', 'integer', 'min:0', 'max:10000000'],
            'monthly_per_tenant' => ['sometimes', 'integer', 'min:0', 'max:100000000'],
            'monthly_platform_requests' => ['sometimes', 'integer', 'min:0', 'max:1000000000'],
            'max_output_tokens' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'temperature' => ['nullable', 'numeric', 'min:0', 'max:2'],
            'timeout_seconds' => ['nullable', 'integer', 'min:1', 'max:600'],
            'max_retries' => ['nullable', 'integer', 'min:0', 'max:10'],
            'description' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
