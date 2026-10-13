<?php

declare(strict_types=1);

namespace App\Fortify\Responses;

use App\Http\Resources\UserResource;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Laravel\Fortify\Contracts\RegisterResponse as RegisterResponseContract;

class RegisterResponse implements RegisterResponseContract
{
    public function toResponse($request): JsonResponse
    {
        return ApiResponse::success(
            'Your Dukavo workspace is ready.',
            ['user' => new UserResource($request->user())],
            201,
        );
    }
}
