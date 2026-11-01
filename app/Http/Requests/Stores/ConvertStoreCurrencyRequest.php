<?php

declare(strict_types=1);

namespace App\Http\Requests\Stores;

use App\Http\Requests\ApiRequest;
use App\Services\Currency\CurrencyCatalog;
use Illuminate\Validation\Rule;

/**
 * Changing a store's base currency.
 *
 * The target must be one of the supported ISO codes. The client also sends an
 * idempotency key so a retried request cannot convert the catalogue twice; when
 * it is absent the controller derives a deterministic key from the request.
 */
class ConvertStoreCurrencyRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $supported = array_keys(app(CurrencyCatalog::class)->all());

        return [
            'currency' => ['required', 'string', 'size:3', 'uppercase', Rule::in($supported)],
            'idempotency_key' => ['required', 'string', 'max:100', 'alpha_dash'],
            'confirm' => ['accepted'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'confirm.accepted' => 'Currency conversion must be explicitly confirmed.',
        ];
    }
}
