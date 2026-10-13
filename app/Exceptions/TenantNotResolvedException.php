<?php

declare(strict_types=1);

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Raised when a request cannot be attributed to a tenant.
 *
 * This is deliberately a distinct exception (rather than a 403) because it is
 * almost always a client integration problem — a missing or unknown
 * `X-Tenant` header — and the client needs to be told exactly that.
 */
class TenantNotResolvedException extends RuntimeException
{
    public function render(Request $request): JsonResponse
    {
        return new JsonResponse([
            'success' => false,
            'message' => $this->getMessage(),
            'errors' => ['tenant' => [$this->getMessage()]],
        ], 400);
    }
}
