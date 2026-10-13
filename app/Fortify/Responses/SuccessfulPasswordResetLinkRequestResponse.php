<?php

declare(strict_types=1);

namespace App\Fortify\Responses;

use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Laravel\Fortify\Contracts\SuccessfulPasswordResetLinkRequestResponse as SuccessfulPasswordResetLinkRequestResponseContract;

/**
 * The response is intentionally identical whether or not the address exists,
 * so the endpoint cannot be used to enumerate registered users.
 */
class SuccessfulPasswordResetLinkRequestResponse implements SuccessfulPasswordResetLinkRequestResponseContract
{
    public function toResponse($request, $status): JsonResponse
    {
        return ApiResponse::success(
            'If that email address exists in our system, a password reset link has been sent.'
        );
    }
}
