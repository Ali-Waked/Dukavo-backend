<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Ai;

use App\Http\Controllers\Controller;
use App\Http\Requests\Ai\SalesCopilotRequest;
use App\Models\Conversation;
use App\Services\Ai\Features\SalesCopilot;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * Sales assistance for the conversation inbox.
 *
 * Read-only by design: it returns a reply and an order draft for the agent to
 * review. It never writes to the conversation, catalogue, inventory or an
 * order, so an unwanted suggestion has no effect until a person acts on it.
 */
final class SalesCopilotController extends Controller
{
    public function __construct(private readonly SalesCopilot $copilot) {}

    public function suggest(SalesCopilotRequest $request, Conversation $conversation): JsonResponse
    {
        $result = $this->copilot->suggest(
            $conversation,
            $request->user(),
            $request->validated('instruction'),
        );

        return ApiResponse::success('Sales copilot suggestion ready for review.', $result);
    }
}
