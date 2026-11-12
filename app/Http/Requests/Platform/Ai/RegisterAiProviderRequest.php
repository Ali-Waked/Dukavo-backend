<?php

declare(strict_types=1);

namespace App\Http\Requests\Platform\Ai;

use App\Http\Requests\ApiRequest;
use App\Services\Ai\Providers\ProviderCatalog;
use Illuminate\Validation\Rule;

/**
 * Registering a provider from the catalogue the factory knows about.
 *
 * The key must be one the factory can build, so the dashboard can never
 * create a row that looks configurable but can never serve a request.
 */
final class RegisterAiProviderRequest extends ApiRequest
{
    /**
     * @return list<string>
     */
    public function catalogKeys(): array
    {
        return array_column(ProviderCatalog::entries(), 'key');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'key' => ['required', 'string', Rule::in($this->catalogKeys())],
            'name' => ['sometimes', 'string', 'max:120'],
            'enabled' => ['sometimes', 'boolean'],
            'priority' => ['sometimes', 'integer', 'min:0', 'max:10000'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
