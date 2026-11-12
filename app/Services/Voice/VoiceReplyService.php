<?php

declare(strict_types=1);

namespace App\Services\Voice;

use App\Enums\MessageType;
use App\Models\ChannelConnection;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Services\Ai\AiGuard;
use App\Services\Ai\AiUsageRecorder;
use App\Services\Ai\Contracts\TextToSpeechProvider;
use App\Services\Ai\Data\AiCompletion;
use App\Services\Ai\Exceptions\AiRequestFailed;
use App\Services\Ai\Exceptions\AiUnavailable;
use App\Services\Channels\ChannelProviderRegistry;
use App\Services\Channels\Data\OutboundMedia;
use App\Services\Channels\Data\OutboundMessage;
use App\Services\Channels\Exceptions\ChannelDeliveryFailed;
use App\Services\Channels\OutboundMessageDispatcher;

/**
 * Turns a written reply into a voice note and sends it on the same channel.
 *
 * This is the outbound half of the voice conversation loop: an inbound voice
 * note is transcribed and understood, and the answer can go back as speech. It
 * is deliberately opt-in per send — nothing here replies automatically, and a
 * channel that cannot carry audio is refused before any bytes are generated.
 *
 * Free-first: the default adapters are self-hosted Piper or Kokoro. A paid
 * adapter is only ever selected when the deployment explicitly authorises it.
 */
final class VoiceReplyService
{
    private const FEATURE = 'voice_reply';

    public function __construct(
        private readonly TextToSpeechProvider $provider,
        private readonly AiGuard $guard,
        private readonly AiUsageRecorder $usage,
        private readonly OutboundMessageDispatcher $dispatcher,
        private readonly ChannelProviderRegistry $connectors,
    ) {}

    /**
     * Whether voice replies can run at all on this installation.
     */
    public function available(): bool
    {
        return (bool) config('ai.tts.enabled') && $this->provider->isConfigured();
    }

    /**
     * Whether this connection's channel can carry a voice note.
     */
    public function supports(ChannelConnection $connection): bool
    {
        if (! $this->available()) {
            return false;
        }

        return ($this->connectors->for($connection->channel)->capabilities()->toArray()['audio'] ?? false) === true;
    }

    /**
     * @throws AiUnavailable
     * @throws AiRequestFailed
     * @throws ChannelDeliveryFailed
     */
    public function reply(
        ChannelConnection $connection,
        Conversation $conversation,
        string $text,
        ?int $userId = null,
        ?string $idempotencyKey = null,
    ): ConversationMessage {
        if (! (bool) config('ai.tts.enabled')) {
            throw AiUnavailable::disabled(self::FEATURE);
        }

        if (! $this->provider->isConfigured()) {
            throw AiUnavailable::notConfigured();
        }

        $config = (array) config('ai.tts', []);

        $this->guard->enforceDailyLimits(self::FEATURE, $config, $conversation->tenant_id, $userId);

        $speech = trim($text);
        $maxChars = (int) ($config['max_chars'] ?? 1200);

        if ($maxChars > 0 && mb_strlen($speech) > $maxChars) {
            // A voice note is spoken, not read: truncating at the configured
            // ceiling keeps a runaway answer from becoming a five-minute file.
            $speech = mb_substr($speech, 0, $maxChars);
        }

        if ($speech === '') {
            throw AiRequestFailed::invalidOutput('the reply text was empty');
        }

        try {
            $synthesis = $this->provider->synthesize($speech);
        } catch (AiUnavailable|AiRequestFailed $exception) {
            $this->usage->recordFailure(
                feature: self::FEATURE,
                tenantId: $conversation->tenant_id,
                userId: $userId,
                reason: $exception->errorCode(),
                model: (string) ($config['model'] ?? null),
                metadata: $exception->context(),
                provider: $this->provider->provider(),
            );

            throw $exception;
        }

        if ($synthesis->isEmpty()) {
            $exception = AiRequestFailed::invalidOutput('the synthesiser returned no audio');

            $this->usage->recordFailure(
                feature: self::FEATURE,
                tenantId: $conversation->tenant_id,
                userId: $userId,
                reason: $exception->errorCode(),
                model: $synthesis->model,
                metadata: $exception->context(),
                provider: $this->provider->provider(),
            );

            throw $exception;
        }

        // The synthesis already happened, so it is accounted for even if the
        // channel later refuses the upload: the engine still ran.
        $this->usage->recordSuccess(
            self::FEATURE,
            new AiCompletion(
                text: '',
                model: $synthesis->model,
                latencyMs: $synthesis->latencyMs,
                providerResponseId: $synthesis->providerResponseId,
            ),
            $conversation->tenant_id,
            $userId,
            $this->provider->provider(),
        );

        return $this->dispatcher->send(
            $connection,
            $conversation,
            OutboundMessage::media(
                MessageType::Audio,
                new OutboundMedia(
                    contents: $synthesis->contents,
                    filename: $synthesis->filename,
                    mimeType: $synthesis->mimeType,
                    asVoiceNote: true,
                ),
            ),
            $userId,
            $idempotencyKey,
        );
    }
}
