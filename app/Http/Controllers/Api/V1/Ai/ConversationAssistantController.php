<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Ai;

use App\Http\Controllers\Controller;
use App\Http\Requests\Ai\DraftReplyRequest;
use App\Models\Conversation;
use App\Services\Ai\Features\ConversationAssistant;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * Reply assistance for the contact inbox.
 *
 * Read-only by design: it returns a draft for the agent to edit and send through
 * the existing reply endpoint. It never writes to a conversation, so a mistaken
 * or unwanted suggestion has no effect until a human acts on it.
 */
class ConversationAssistantController extends Controller
{
    public function __construct(private readonly ConversationAssistant $assistant) {}

    public function draftReply(DraftReplyRequest $request, Conversation $conversation): JsonResponse
    {
        $result = $this->assistant->draftReply(
            $conversation,
            $request->validated('instruction'),
            (int) $request->user()->getKey(),
        );

        return ApiResponse::success('Draft reply ready for review.', $result);
    }
}
