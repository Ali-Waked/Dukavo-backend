<?php

declare(strict_types=1);

namespace App\Fortify\Responses;

use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Laravel\Fortify\Contracts\EmailVerificationNotificationSentResponse as EmailVerificationNotificationSentResponseContract;

class EmailVerificationNotificationSentResponse implements EmailVerificationNotificationSentResponseContract
{
    public function toResponse($request): JsonResponse
    {
        return ApiResponse::success('A fresh verification link has been sent to your email address.');
    }
}
