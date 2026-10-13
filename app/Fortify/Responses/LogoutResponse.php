<?php

declare(strict_types=1);

namespace App\Fortify\Responses;

use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Laravel\Fortify\Contracts\LogoutResponse as LogoutResponseContract;

class LogoutResponse implements LogoutResponseContract
{
    public function toResponse($request): JsonResponse
    {
        return ApiResponse::success('Signed out successfully.');
    }
}
