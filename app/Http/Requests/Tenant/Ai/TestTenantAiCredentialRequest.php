<?php

declare(strict_types=1);

namespace App\Http\Requests\Tenant\Ai;

use App\Http\Requests\ApiRequest;
use Illuminate\Validation\Rule;

/**
 * Choosing which of the workspace's providers to run a connection check
 * against. The credential already exists; nothing new is submitted.
 */
final class TestTenantAiCredentialRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'provider' => [
                'required',
                'string',
                Rule::exists('ai_providers', 'key')->where('enabled', true),
            ],
        ];
    }
}
