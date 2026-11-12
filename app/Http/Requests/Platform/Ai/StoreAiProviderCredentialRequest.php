<?php

declare(strict_types=1);

namespace App\Http\Requests\Platform\Ai;

use App\Http\Requests\ApiRequest;
use App\Services\Ai\Providers\ProviderCatalog;

/**
 * Storing or rotating a provider's platform credential.
 *
 * The plaintext key arrives here once and is handed straight to the admin
 * service, which encrypts it before it touches the database. The base URL
 * is operator input and is additionally screened against the SSRF guard in
 * the service — validation alone cannot know which hosts are safe.
 */
final class StoreAiProviderCredentialRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'api_key' => ['required', 'string', 'min:8', 'max:512'],
            // The SSRF screen runs here so a refused endpoint is a 422 the
            // operator can act on; the service re-checks before storing.
            'base_url' => ['nullable', 'url', 'max:255', function (string $attribute, mixed $value, \Closure $fail): void {
                if (is_string($value) && ! ProviderCatalog::isSafeBaseUrl($value)) {
                    $fail('The base URL must be a public provider endpoint, not a local or private address.');
                }
            }],
            'model' => ['nullable', 'string', 'max:190'],
        ];
    }
}
