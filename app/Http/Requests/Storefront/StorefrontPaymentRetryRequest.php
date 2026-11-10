<?php

declare(strict_types=1);

namespace App\Http\Requests\Storefront;

use App\Http\Requests\ApiRequest;

/**
 * A public request to start the next payment attempt for an existing order.
 *
 * The tracking token in the URL is the only authority; the body may name an
 * eligible online rail but never a provider, account, amount or currency.
 */
class StorefrontPaymentRetryRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'payment_method_code' => ['nullable', 'string', 'max:40', 'regex:/^[a-z0-9_]+$/'],
        ];
    }
}
