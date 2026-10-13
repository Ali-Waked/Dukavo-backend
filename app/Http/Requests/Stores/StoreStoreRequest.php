<?php

declare(strict_types=1);

namespace App\Http\Requests\Stores;

use App\Enums\StoreStatus;
use App\Http\Requests\ApiRequest;
use Illuminate\Validation\Rule;

/**
 * Creating a store.
 */
class StoreStoreRequest extends ApiRequest
{
    protected function prepareForValidation(): void
    {
        $this->prepareSlug();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'slug' => ['nullable', 'string', 'max:120', 'alpha_dash', $this->tenantUnique('stores', 'slug')],
            'status' => ['sometimes', Rule::enum(StoreStatus::class)],
            'email' => ['nullable', 'email:rfc', 'max:190'],
            'phone' => ['nullable', 'string', 'max:32'],
            'currency' => ['nullable', 'string', 'size:3', 'uppercase'],
            'timezone' => ['nullable', 'timezone'],
            'is_default' => ['sometimes', 'boolean'],
        ];
    }
}
