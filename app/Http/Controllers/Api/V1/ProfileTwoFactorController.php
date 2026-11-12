<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Profile\ConfirmTwoFactorRequest;
use App\Http\Requests\Profile\DisableTwoFactorRequest;
use App\Http\Requests\Profile\RegenerateRecoveryCodesRequest;
use App\Services\Security\TwoFactorService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * The account holder's own TOTP settings.
 *
 * The secret is returned only once, during setup, and never again. Recovery
 * codes are returned only when they are created or regenerated. Enabling
 * requires a valid code first, and disabling requires the current password plus
 * a current factor, so neither a stolen session nor a stolen password alone can
 * silently remove the protection.
 */
class ProfileTwoFactorController extends Controller
{
    public function __construct(private readonly TwoFactorService $twoFactor) {}

    public function show(Request $request): JsonResponse
    {
        return ApiResponse::success('Two-factor status loaded.', $this->twoFactor->status($request->user()));
    }

    public function setup(Request $request): JsonResponse
    {
        return ApiResponse::success('Two-factor setup started.', $this->twoFactor->setup($request->user()));
    }

    public function confirm(ConfirmTwoFactorRequest $request): JsonResponse
    {
        $result = $this->twoFactor->confirm($request->user(), (string) $request->validated('code'));

        return ApiResponse::success('Two-factor authentication enabled.', [
            ...$result,
            'two_factor' => $this->twoFactor->status($request->user()->fresh() ?? $request->user()),
        ]);
    }

    public function regenerate(RegenerateRecoveryCodesRequest $request): JsonResponse
    {
        $codes = $this->twoFactor->regenerateRecoveryCodes($request->user(), (string) $request->validated('code'));

        return ApiResponse::success('Recovery codes regenerated.', [
            'codes' => $codes,
            'recovery_codes_count' => count($codes),
        ]);
    }

    public function destroy(DisableTwoFactorRequest $request): JsonResponse
    {
        $user = $request->user();
        $password = (string) $request->validated('password');

        // Password verification happens in the service, but doing it here as
        // well keeps the failure shape a 422 on the password field rather than a
        // generic refusal from deeper in the flow.
        if (! Hash::check($password, $user->password)) {
            throw ValidationException::withMessages([
                'password' => 'The current password is incorrect.',
            ]);
        }

        $this->twoFactor->disable(
            $user,
            $password,
            $request->validated('code') === null ? null : (string) $request->validated('code'),
            $request->validated('recovery_code') === null ? null : (string) $request->validated('recovery_code'),
        );

        return ApiResponse::success('Two-factor authentication disabled.', [
            'two_factor' => $this->twoFactor->status($user->fresh() ?? $user),
        ]);
    }
}
