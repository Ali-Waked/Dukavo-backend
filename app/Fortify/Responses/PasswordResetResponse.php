<?php

declare(strict_types=1);

namespace App\Fortify\Responses;

use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Laravel\Fortify\Contracts\PasswordResetResponse as PasswordResetResponseContract;

class PasswordResetResponse implements PasswordResetResponseContract
{
    public function toResponse($request, $status): JsonResponse
    {
        return ApiResponse::success('Your password has been reset. You may now sign in.');
    }
}
