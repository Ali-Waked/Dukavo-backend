<?php

declare(strict_types=1);

namespace App\Services\Voice;

use App\Models\VoiceMessage;
use App\Services\Ai\AiGuard;
use App\Services\Ai\AiUsageRecorder;
use App\Services\Ai\Contracts\SpeechToTextProvider;
use App\Services\Ai\Data\AiCompletion;
use App\Services\Ai\Data\SpeechTranscription;
use App\Services\Ai\Exceptions\AiRequestFailed;
use App\Services\Ai\Exceptions\AiUnavailable;

/**
 * Runs one transcription under the same budget and accounting rules as every
 * other AI feature.
 */
final class VoiceTranscriptionService
{
    private const FEATURE = 'voice_transcription';

    public function __construct(
        private readonly SpeechToTextProvider $provider,
        private readonly AiGuard $guard,
        private readonly AiUsageRecorder $usage,
    ) {}

    public function transcribe(VoiceMessage $voice, string $contents): SpeechTranscription
    {
        $config = $this->guard->feature(self::FEATURE);

        if (! $this->provider->isConfigured()) {
            throw AiUnavailable::notConfigured();
        }

        $this->guard->enforceDailyLimits(self::FEATURE, $config, $voice->tenant_id, null);

        try {
            $result = $this->provider->transcribe(
                $contents,
                $voice->media_filename ?: 'voice-note.ogg',
                $voice->media_mime_type ?: 'audio/ogg',
            );
        } catch (AiUnavailable|AiRequestFailed $exception) {
            $this->usage->recordFailure(
                feature: self::FEATURE,
                tenantId: $voice->tenant_id,
                userId: null,
                reason: $exception->errorCode(),
                model: (string) config('ai.transcription.model'),
                metadata: $exception->context(),
                provider: $this->provider->provider(),
            );

            throw $exception;
        }

        $this->usage->recordSuccess(
            self::FEATURE,
            new AiCompletion(
                text: $result->text,
                model: $result->model,
                latencyMs: $result->latencyMs,
                providerResponseId: $result->providerResponseId,
            ),
            $voice->tenant_id,
            null,
            $this->provider->provider(),
        );

        return $result;
    }
}
