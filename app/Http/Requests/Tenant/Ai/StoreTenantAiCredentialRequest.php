<?php

declare(strict_types=1);

namespace App\Http\Requests\Tenant\Ai;

use App\Http\Requests\ApiRequest;
use App\Services\Ai\Providers\ProviderCatalog;
use Illuminate\Validation\Rule;

/**
 * Storing or rotating the workspace's own credential for a provider.
 *
 * Only reachable when the platform has enabled BYOK; the plaintext key
 * arrives here once, is encrypted by the service before it touches the
 * database, and never travels back out.
 */
final class StoreTenantAiCredentialRequest extends ApiRequest
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
            'api_key' => ['required', 'string', 'min:8', 'max:512'],
            // The SSRF screen runs here so a refused endpoint is a 422 the
            // merchant can act on; the service re-checks before storing.
            'base_url' => ['nullable', 'url', 'max:255', function (string $attribute, mixed $value, \Closure $fail): void {
                if (is_string($value) && ! ProviderCatalog::isSafeBaseUrl($value)) {
                    $fail('The base URL must be a public provider endpoint, not a local or private address.');
                }
            }],
            'model' => ['nullable', 'string', 'max:190'],
        ];
    }
}
