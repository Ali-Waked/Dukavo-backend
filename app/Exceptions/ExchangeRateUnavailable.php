<?php

declare(strict_types=1);

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * No trustworthy exchange rate could be obtained.
 *
 * This is not a validation error: the request was well formed, but the platform
 * cannot price the operation. It renders as `503` and no monetary data is
 * touched, so a provider outage can never leave a store half-converted.
 */
class ExchangeRateUnavailable extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(string $message, private readonly array $context = [])
    {
        parent::__construct($message);
    }

    /**
     * @return array<string, mixed>
     */
    public function context(): array
    {
        return $this->context;
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $this->getMessage(),
            'code' => 'exchange_rate_unavailable',
        ], 503);
    }
}
