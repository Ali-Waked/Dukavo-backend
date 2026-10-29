<?php

declare(strict_types=1);

namespace App\Services\Voice;

use App\Enums\VoiceMessageStatus;
use App\Models\VoiceMessage;
use App\Services\Ai\Exceptions\AiRequestFailed;
use App\Services\Ai\Exceptions\AiUnavailable;
use App\Services\Ai\Features\VoiceOrderExtractor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Runs the voice pipeline for one claimed message.
 *
 * Transcription and extraction are separate states so the UI can explain where
 * a recording is in the process. A failure is persisted, not hidden, and only a
 * person can retry it.
 */
final class VoiceMessageProcessor
{
    public function __construct(
        private readonly VoiceTranscriptionService $transcription,
        private readonly VoiceOrderExtractor $extractor,
    ) {}

    public function process(VoiceMessage $voice): VoiceMessage
    {
        if (! $this->claim($voice)) {
            return $voice->refresh();
        }

        $voice->refresh();
        $conversation = $voice->conversation;

        if ($conversation === null) {
            return $this->fail($voice, 'conversation_missing', 'The conversation for this recording no longer exists.');
        }

        $path = $voice->media_path;

        if (! is_string($path) || $path === '' || ! Storage::disk('local')->exists($path)) {
            return $this->fail($voice, 'media_unavailable', 'The voice attachment is no longer available.');
        }

        $contents = Storage::disk('local')->get($path);

        if (! is_string($contents) || $contents === '') {
            return $this->fail($voice, 'media_unavailable', 'The voice attachment is empty.');
        }

        try {
            $result = $this->transcription->transcribe($voice, $contents);

            $voice->forceFill([
                'transcript' => $result->text,
                'language' => $result->language,
                'confidence' => $result->confidence,
                'provider' => $this->transcriptionProvider(),
                'model' => $result->model,
                'transcription_latency_ms' => $result->latencyMs,
                'status' => VoiceMessageStatus::Extracting,
            ])->save();

            $extraction = $this->extractor->extract($voice, $conversation);

            $voice->forceFill([
                'extraction' => $extraction,
                'status' => VoiceMessageStatus::Ready,
                'failure_code' => null,
                'failure_message' => null,
                'processed_at' => now(),
            ])->save();

            return $voice->refresh();
        } catch (AiUnavailable|AiRequestFailed $exception) {
            Log::warning('Voice message processing failed.', [
                'voice_message_id' => $voice->getKey(),
                'code' => $exception->errorCode(),
            ]);

            return $this->fail($voice, $exception->errorCode(), $exception->getMessage());
        } catch (Throwable $exception) {
            report($exception);

            return $this->fail($voice, 'voice_processing_failed', 'The recording could not be processed.');
        }
    }

    /**
     * Atomically take ownership of a queued or failed voice message.
     */
    private function claim(VoiceMessage $voice): bool
    {
        $updated = VoiceMessage::query()
            ->whereKey($voice->getKey())
            ->whereIn('status', [VoiceMessageStatus::Queued->value, VoiceMessageStatus::Failed->value])
            ->update([
                'status' => VoiceMessageStatus::Transcribing->value,
                'attempt_count' => DB::raw('attempt_count + 1'),
                'transcript' => null,
                'language' => null,
                'confidence' => null,
                'provider' => null,
                'model' => null,
                'transcription_latency_ms' => null,
                'extraction' => null,
                'failure_code' => null,
                'failure_message' => null,
                'processed_at' => null,
            ]);

        return $updated === 1;
    }

    private function transcriptionProvider(): string
    {
        return (string) (config('ai.transcription.provider') ?: 'openai');
    }

    private function fail(VoiceMessage $voice, string $code, string $message): VoiceMessage
    {
        $voice->forceFill([
            'status' => VoiceMessageStatus::Failed,
            'failure_code' => $code,
            'failure_message' => Str::limit($message, 500, ''),
            'processed_at' => now(),
        ])->save();

        return $voice->refresh();
    }
}
