<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\VoiceMessageStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Voice\ApplyVoiceMessageRequest;
use App\Http\Resources\CartResource;
use App\Http\Resources\VoiceMessageResource;
use App\Jobs\ProcessVoiceMessage;
use App\Models\Conversation;
use App\Models\VoiceMessage;
use App\Services\Voice\VoiceDraftApplier;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * Review, retry and apply a voice note.
 *
 * Viewing is a conversation read. Retrying spends AI budget, and applying uses
 * the order permissions as well as the AI permission: a voice draft is never
 * allowed to bypass the Smart Cart's own authorization and stock rules.
 */
class VoiceMessageController extends Controller
{
    public function __construct(private readonly VoiceDraftApplier $applier) {}

    public function show(Conversation $conversation, VoiceMessage $voiceMessage): VoiceMessageResource
    {
        $this->assertBelongs($conversation, $voiceMessage);

        return new VoiceMessageResource($voiceMessage);
    }

    public function retry(Conversation $conversation, VoiceMessage $voiceMessage): JsonResponse
    {
        $this->assertBelongs($conversation, $voiceMessage);

        if ($voiceMessage->status !== VoiceMessageStatus::Failed) {
            return ApiResponse::error('Only a failed voice recording can be retried.', 409);
        }

        if (! $voiceMessage->mediaAvailable()) {
            return ApiResponse::error('The original recording is no longer available.', 422);
        }

        $voiceMessage->forceFill([
            'status' => VoiceMessageStatus::Queued,
            'failure_code' => null,
            'failure_message' => null,
        ])->save();

        ProcessVoiceMessage::dispatch(
            (int) $voiceMessage->getKey(),
            (int) $voiceMessage->tenant_id,
        )->afterCommit();

        return ApiResponse::success(
            'Voice recording queued again.',
            new VoiceMessageResource($voiceMessage->refresh()),
        );
    }

    public function apply(
        ApplyVoiceMessageRequest $request,
        Conversation $conversation,
        VoiceMessage $voiceMessage,
    ): JsonResponse {
        $this->assertBelongs($conversation, $voiceMessage);

        $cart = $this->applier->apply(
            $voiceMessage,
            $request->user(),
            (bool) $request->validated('confirmed'),
            array_values((array) $request->validated('items')),
        );

        return ApiResponse::success('Voice order added to the cart.', new CartResource($cart));
    }

    private function assertBelongs(Conversation $conversation, VoiceMessage $voiceMessage): void
    {
        abort_unless((int) $voiceMessage->conversation_id === (int) $conversation->getKey(), 404);
    }
}
