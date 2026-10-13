<?php

declare(strict_types=1);

namespace App\Fortify\Responses;

use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Laravel\Fortify\Contracts\FailedPasswordResetLinkRequestResponse as FailedPasswordResetLinkRequestResponseContract;

class FailedPasswordResetLinkRequestResponse implements FailedPasswordResetLinkRequestResponseContract
{
    public function toResponse($request, $status): JsonResponse
    {
        return ApiResponse::success(
            'If that email address exists in our system, a password reset link has been sent.'
        );
    }
}
