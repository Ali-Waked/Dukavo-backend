<?php

declare(strict_types=1);

namespace App\Http\Requests\Platform\Ai;

use App\Http\Requests\ApiRequest;
use App\Services\Ai\Support\AiCapability;
use Illuminate\Validation\Rule;

/**
 * Declaring a model a provider offers.
 *
 * Capabilities are the same enum the router matches against, so declaring
 * one here immediately affects which tasks a model may serve.
 */
final class StoreAiModelRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'key' => ['required', 'string', 'max:190'],
            'display_name' => ['required', 'string', 'max:190'],
            'capabilities' => ['required', 'array', 'min:1'],
            'capabilities.*' => [Rule::enum(AiCapability::class)],
            'is_active' => ['sometimes', 'boolean'],
            'is_default' => ['sometimes', 'boolean'],
            'input_price' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'output_price' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'context_window' => ['nullable', 'integer', 'min:0', 'max:100000000'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
