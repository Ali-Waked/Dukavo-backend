<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Profile\CompleteOnboardingRequest;
use App\Http\Resources\ProfileResource;
use App\Services\Identity\OnboardingService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The one-time first-login preference confirmation.
 *
 * These routes sit outside the tenant group because preferences belong to the
 * person, not to a workspace. The flow never blocks access: `skip` marks it
 * done, and every profile field remains editable afterwards.
 */
class ProfileOnboardingController extends Controller
{
    public function __construct(private readonly OnboardingService $onboarding) {}

    public function show(Request $request): JsonResponse
    {
        return ApiResponse::success(
            'Onboarding status loaded.',
            $this->onboarding->status($request->user(), $request),
        );
    }

    public function store(CompleteOnboardingRequest $request): JsonResponse
    {
        $user = $this->onboarding->complete($request->user(), $request->validated(), $request);

        return ApiResponse::success(
            'Preferences saved.',
            new ProfileResource($user->fresh() ?? $user),
        );
    }

    public function skip(Request $request): JsonResponse
    {
        $user = $this->onboarding->skip($request->user());

        return ApiResponse::success('Onboarding skipped.', [
            'required' => $this->onboarding->isRequired($user),
            'completed_at' => $user->preferences_initialized_at?->toIso8601String(),
        ]);
    }
}
