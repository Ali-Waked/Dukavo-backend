<?php

declare(strict_types=1);

namespace App\Http\Requests\Platform\Ai;

use App\Http\Requests\ApiRequest;

/**
 * Editing a provider's identity and commercial posture.
 *
 * The key and driver are immutable: they are what credentials, routing and
 * the factory all reference, so re-purposing a row is refused — register a
 * new provider instead.
 */
final class UpdateAiProviderRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:120'],
            'enabled' => ['sometimes', 'boolean'],
            'priority' => ['sometimes', 'integer', 'min:0', 'max:10000'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
