<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Ai;

use App\Http\Controllers\Controller;
use App\Http\Requests\Ai\MerchantCopilotRequest;
use App\Services\Ai\Features\MerchantCopilot;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

final class MerchantCopilotController extends Controller
{
    public function __construct(private readonly MerchantCopilot $copilot) {}

    public function ask(MerchantCopilotRequest $request): JsonResponse
    {
        $result = $this->copilot->answer(
            $request->validated('question'),
            $request->validated('store_id'),
            $request->validated('from'),
            $request->validated('until'),
            (int) $request->user()->getKey(),
        );

        return ApiResponse::success('Merchant copilot answer ready.', $result);
    }
}
