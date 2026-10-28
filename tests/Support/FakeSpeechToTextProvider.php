<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Services\Ai\Contracts\SpeechToTextProvider;
use App\Services\Ai\Data\SpeechTranscription;
use Throwable;

/**
 * An in-memory speech provider. It never reaches a network or a paid service.
 */
final class FakeSpeechToTextProvider implements SpeechToTextProvider
{
    /** @var list<array{filename: string, mime_type: string, bytes: int}> */
    public array $calls = [];

    public function __construct(
        private readonly ?SpeechTranscription $result = null,
        private readonly ?Throwable $throw = null,
    ) {}

    public function provider(): string
    {
        return 'fake';
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function transcribe(
        string $contents,
        string $filename,
        string $mimeType,
        ?string $language = null,
    ): SpeechTranscription {
        $this->calls[] = [
            'filename' => $filename,
            'mime_type' => $mimeType,
            'bytes' => strlen($contents),
        ];

        if ($this->throw !== null) {
            throw $this->throw;
        }

        return $this->result ?? new SpeechTranscription(
            text: 'أريد طلب قطعتين من المنتج.',
            language: 'ar',
            durationSeconds: 4.2,
            confidence: 0.93,
            model: 'fake-whisper',
            latencyMs: 120,
        );
    }
}
