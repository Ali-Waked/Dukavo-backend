<?php

declare(strict_types=1);

namespace App\Http\Requests\Platform\Ai;

use App\Http\Requests\ApiRequest;
use Illuminate\Validation\Rule;

/**
 * Replacing a task's platform routing chain.
 *
 * The chain replaces the whole chain (see the admin service); every hop
 * must point at a real provider. Per-hop overrides of `0` mean "inherit
 * the task's policy" exactly as the task model documents.
 */
final class SyncAiTaskRoutesRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'routes' => ['required', 'array', 'min:1', 'max:10'],
            'routes.*.provider_id' => ['required', 'integer', Rule::exists('ai_providers', 'id')],
            'routes.*.model' => ['nullable', 'string', 'max:190'],
            'routes.*.priority' => ['sometimes', 'integer', 'min:0', 'max:10000'],
            'routes.*.is_fallback' => ['sometimes', 'boolean'],
            'routes.*.max_output_tokens' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'routes.*.temperature' => ['nullable', 'numeric', 'min:0', 'max:2'],
            'routes.*.timeout_seconds' => ['sometimes', 'integer', 'min:0', 'max:600'],
            'routes.*.max_retries' => ['sometimes', 'integer', 'min:0', 'max:10'],
        ];
    }

    /**
     * Normalised hop list, in submitted order: the first hop is the
     * primary, the rest are fallbacks in listed order.
     *
     * @return list<array<string, mixed>>
     */
    public function routes(): array
    {
        return array_values((array) $this->validated('routes'));
    }
}
