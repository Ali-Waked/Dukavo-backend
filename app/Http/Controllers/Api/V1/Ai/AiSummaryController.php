<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Ai;

use App\Http\Controllers\Controller;
use App\Http\Requests\Ai\SummariseConversationRequest;
use App\Jobs\RunAiUseCase;
use App\Models\Conversation;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

final class AiSummaryController extends Controller
{
    public function dispatch(SummariseConversationRequest $request, Conversation $conversation): JsonResponse
    {
        $actor = $request->user();

        RunAiUseCase::dispatch('summary', [
            'conversation_id' => (int) $conversation->getKey(),
            'user_id' => (int) $actor?->getKey(),
        ], $conversation->tenant_id);

        return ApiResponse::success('Summary queued.', [
            'status' => 'queued',
            'conversation_id' => $conversation->getKey(),
        ]);
    }
}
