<?php

declare(strict_types=1);

namespace App\Services\Voice;

use App\Enums\MessageDirection;
use App\Enums\MessageType;
use App\Enums\VoiceMessageStatus;
use App\Jobs\ProcessVoiceMessage;
use App\Models\ConversationMessage;
use App\Models\VoiceMessage;

/**
 * Bridges an inbound WhatsApp audio message to the voice pipeline.
 *
 * Registration is idempotent: a provider redelivery or a replay cannot create a
 * second voice record for the same message. A message whose media never arrived
 * is still recorded, with an honest failure, so the inbox can explain it.
 */
final class VoiceMessageRegistrar
{
    public function register(ConversationMessage $message): ?VoiceMessage
    {
        if ($message->direction !== MessageDirection::Inbound
            || $message->type !== MessageType::Audio
            || $message->is_internal) {
            return null;
        }

        $message->loadMissing('conversation');

        if ($message->conversation?->channel !== 'whatsapp') {
            return null;
        }

        $media = data_get($message->meta, 'media');
        $hasMedia = is_array($media) && filled($media['path'] ?? null);

        $voice = VoiceMessage::query()->firstOrCreate(
            ['conversation_message_id' => $message->getKey()],
            [
                'tenant_id' => $message->tenant_id,
                'conversation_id' => $message->conversation_id,
                'status' => $hasMedia ? VoiceMessageStatus::Queued : VoiceMessageStatus::Failed,
                'media_path' => $hasMedia ? (string) $media['path'] : null,
                'media_mime_type' => $hasMedia ? ($media['mime_type'] ?? null) : null,
                'media_size' => $hasMedia ? ($media['size'] ?? null) : null,
                'media_filename' => $hasMedia ? ($media['filename'] ?? null) : null,
                'failure_code' => $hasMedia ? null : 'media_unavailable',
                'failure_message' => $hasMedia ? null : 'The voice attachment could not be downloaded.',
            ],
        );

        if ($hasMedia && $voice->status === VoiceMessageStatus::Failed && $voice->failure_code === 'media_unavailable') {
            $voice->forceFill([
                'status' => VoiceMessageStatus::Queued,
                'media_path' => (string) $media['path'],
                'media_mime_type' => $media['mime_type'] ?? null,
                'media_size' => $media['size'] ?? null,
                'media_filename' => $media['filename'] ?? null,
                'failure_code' => null,
                'failure_message' => null,
            ])->save();
        }

        if ($hasMedia && in_array($voice->status, [VoiceMessageStatus::Queued, VoiceMessageStatus::Failed], true)) {
            ProcessVoiceMessage::dispatch((int) $voice->getKey(), (int) $voice->tenant_id)->afterCommit();
        }

        return $voice;
    }
}
