<?php

declare(strict_types=1);

namespace App\Http\Requests\Ai;

use App\Http\Requests\ApiRequest;

/**
 * A model-proposed business action.
 *
 * The request only carries the proposal. It never carries the resolved record
 * data, the classification, the permission or the price — the executor and the
 * policy own those, so a crafted request cannot lower an action's risk.
 */
class ProposeAiActionRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'use_case' => ['required', 'string', 'max:60', 'regex:/^[a-z0-9_.-]+$/'],
            'action_type' => ['required', 'string', 'max:60'],
            'summary' => ['nullable', 'string', 'max:255'],
            'idempotency_key' => ['nullable', 'string', 'max:120'],
            'payload' => ['required', 'array'],
        ];
    }
}
