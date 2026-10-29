<?php

declare(strict_types=1);

namespace App\Services\Channels\WhatsApp\Webhooks;

use App\Enums\MessageType;

/**
 * Turns a Meta change value into provider-neutral events.
 *
 * Keeping the payload shape here means the processor never reaches into
 * `message[$type]['id']` style arrays, and a future provider adapter only has to
 * produce the same two DTOs.
 */
class WhatsAppWebhookParser
{
    /**
     * @param  array<string, mixed>  $value
     * @return list<WhatsAppWebhookEvent>
     */
    public function parse(array $value): array
    {
        $events = [];

        foreach ($value['statuses'] ?? [] as $status) {
            if (! is_array($status)) {
                continue;
            }

            $messageId = trim((string) ($status['id'] ?? ''));

            if ($messageId === '') {
                continue;
            }

            $state = $this->deliveryState((string) ($status['status'] ?? ''));
            $timestamp = $this->timestamp($status['timestamp'] ?? null);
            $error = is_array($status['errors'][0] ?? null) ? $status['errors'][0] : [];

            $events[] = new WhatsAppDeliveryReceipt(
                eventKey: sprintf('status:%s:%s:%s', $messageId, $state, (string) ($timestamp ?? '')),
                messageId: $messageId,
                state: $state,
                failureReason: isset($error['title']) ? (string) $error['title'] : null,
                failureCode: isset($error['code']) ? (string) $error['code'] : null,
                timestamp: $timestamp,
            );
        }

        foreach ($value['messages'] ?? [] as $message) {
            if (! is_array($message)) {
                continue;
            }

            $messageId = trim((string) ($message['id'] ?? ''));
            $from = trim((string) ($message['from'] ?? ''));

            if ($messageId === '' || $from === '') {
                continue;
            }

            $rawType = (string) ($message['type'] ?? 'text');
            $type = $this->messageType($rawType);

            if ($type === null) {
                continue;
            }

            $payload = is_array($message[$rawType] ?? null) ? $message[$rawType] : [];
            $caption = isset($payload['caption']) ? (string) $payload['caption'] : null;
            $body = $rawType === 'text'
                ? (string) ($payload['body'] ?? '')
                : ($caption ?? $this->interactiveBody($rawType, $payload));

            $meta = ['provider' => 'meta', 'raw_type' => $rawType];

            if (isset($message['profile']['name'])) {
                $meta['profile_name'] = (string) $message['profile']['name'];
            }

            if ($rawType === 'location' && isset($payload['latitude'], $payload['longitude'])) {
                $meta['location'] = [
                    'latitude' => $payload['latitude'],
                    'longitude' => $payload['longitude'],
                    'name' => $payload['name'] ?? null,
                    'address' => $payload['address'] ?? null,
                ];
            }

            $isMedia = in_array($rawType, ['image', 'audio', 'voice', 'video', 'document', 'sticker'], true);

            $events[] = new InboundWhatsAppMessage(
                eventKey: 'message:'.$messageId,
                messageId: $messageId,
                from: $from,
                profileName: isset($meta['profile_name']) ? (string) $meta['profile_name'] : null,
                type: $type,
                body: $body,
                mediaId: $isMedia && filled($payload['id'] ?? null) ? (string) $payload['id'] : null,
                mediaMime: $isMedia && filled($payload['mime_type'] ?? null) ? (string) $payload['mime_type'] : null,
                mediaFilename: $isMedia && filled($payload['filename'] ?? null) ? (string) $payload['filename'] : null,
                meta: $meta,
                timestamp: $this->timestamp($message['timestamp'] ?? null),
            );
        }

        return $events;
    }

    private function messageType(string $type): ?MessageType
    {
        return match ($type) {
            'text', 'button', 'interactive', 'location', 'contacts' => MessageType::Text,
            'image' => MessageType::Image,
            'audio', 'voice' => MessageType::Audio,
            'video' => MessageType::Video,
            'document', 'sticker' => MessageType::File,
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function interactiveBody(string $rawType, array $payload): ?string
    {
        return match ($rawType) {
            'button' => $payload['text'] ?? null,
            'interactive' => $payload['button_reply']['title']
                ?? $payload['list_reply']['title']
                ?? null,
            default => null,
        };
    }

    private function deliveryState(string $status): string
    {
        return match (strtolower($status)) {
            '2', 'delivered' => 'delivered',
            '3', 'read' => 'read',
            'failed' => 'failed',
            default => 'sent',
        };
    }

    private function timestamp(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }
}
