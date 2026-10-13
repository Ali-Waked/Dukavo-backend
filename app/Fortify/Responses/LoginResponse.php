<?php

declare(strict_types=1);

namespace App\Fortify\Responses;

use App\Http\Resources\UserResource;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;

/**
 * Fortify redirects to a named route after a successful login. Dukavo has no
 * web routes, so the contract resolves to the JSON payload the SPA needs to
 * bootstrap itself (user, tenants, permissions) in a single round trip.
 */
class LoginResponse implements LoginResponseContract
{
    public function toResponse($request): JsonResponse
    {
        return ApiResponse::success(
            'Signed in successfully.',
            ['user' => new UserResource($request->user())],
        );
    }
}
