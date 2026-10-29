<?php

declare(strict_types=1);

namespace App\Services\Channels\WhatsApp\Webhooks;

use App\Enums\MessageDirection;
use App\Models\ChannelWebhookEvent;
use App\Models\Customer;
use App\Models\WhatsappAccount;
use App\Services\Conversations\ConversationService;
use App\Services\Conversations\WhatsAppMediaService;
use App\Services\Voice\VoiceMessageRegistrar;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Applies parsed provider events exactly once.
 *
 * The ledger is claimed before anything else happens. If processing fails the
 * event row stays with `processed_at = null`, so a provider redelivery can try
 * again without duplicating the work that already succeeded. A processed event
 * is never replayed, even when Meta sends the identical payload repeatedly.
 */
class WhatsAppWebhookProcessor
{
    public function __construct(
        private readonly WhatsAppWebhookParser $parser,
        private readonly ConversationService $conversations,
        private readonly WhatsAppMediaService $media,
        private readonly VoiceMessageRegistrar $voices,
    ) {}

    /**
     * @param  array<string, mixed>  $value
     */
    public function process(WhatsappAccount $account, array $value): void
    {
        foreach ($this->parser->parse($value) as $event) {
            $claim = ChannelWebhookEvent::query()->firstOrCreate(
                [
                    'tenant_id' => $account->tenant_id,
                    'provider' => 'whatsapp',
                    'event_key' => $event->eventKey(),
                ],
                [
                    'event_type' => $event->eventType(),
                    'payload_hash' => hash('sha256', $event->eventKey()),
                ],
            );

            if (! $claim->wasRecentlyCreated && $claim->isProcessed()) {
                continue;
            }

            try {
                if ($event instanceof InboundWhatsAppMessage) {
                    $this->handleMessage($account, $event);
                } elseif ($event instanceof WhatsAppDeliveryReceipt) {
                    $this->handleReceipt($event);
                }

                $claim->forceFill(['processed_at' => now(), 'failure' => null])->save();
            } catch (Throwable $exception) {
                $claim->forceFill(['failure' => Str::limit($exception->getMessage(), 2000, '')])->save();

                // The controller still acknowledges the delivery: Meta retrying a
                // bug in our code forever would not fix the bug. The ledger row
                // keeps the failure visible for retry or reconciliation.
                Log::error('WhatsApp webhook event failed.', [
                    'event_key' => $event->eventKey(),
                    'event_type' => $event->eventType(),
                    'account_id' => $account->getKey(),
                    'message' => $exception->getMessage(),
                ]);
            }
        }
    }

    private function handleMessage(WhatsappAccount $account, InboundWhatsAppMessage $event): void
    {
        $customer = $this->resolveCustomer($account, $event);
        $conversation = $this->conversations->openForCustomer(
            $customer,
            'whatsapp',
            $event->from,
            [
                'whatsapp_account_id' => $account->getKey(),
                'store_id' => $account->store_id,
                'display_name' => $event->profileName,
                'phone' => $event->from,
            ],
        );

        $meta = $event->meta;

        if ($event->mediaId !== null) {
            try {
                $meta['media'] = $this->media->storeInbound($conversation, $event->mediaId, $event->mediaMime);

                if ($event->mediaFilename !== null) {
                    $meta['media']['filename'] = $event->mediaFilename;
                }
            } catch (Throwable $exception) {
                // The text/caption is still valuable even when the attachment
                // cannot be stored. The UI will render an explicit unavailable
                // attachment rather than a broken image.
                $meta['media_error'] = 'Attachment could not be downloaded.';

                Log::warning('WhatsApp media could not be stored.', [
                    'conversation_id' => $conversation->getKey(),
                    'media_id' => $event->mediaId,
                    'message' => $exception->getMessage(),
                ]);
            }
        }

        $message = $this->conversations->addMessage($conversation, MessageDirection::Inbound, [
            'external_id' => $event->messageId,
            'type' => $event->type,
            'body' => $event->body,
            'meta' => $meta,
            'sent_at' => $event->timestamp !== null
                ? CarbonImmutable::createFromTimestampUTC($event->timestamp)
                : now(),
        ]);

        $this->voices->register($message);
    }

    private function handleReceipt(WhatsAppDeliveryReceipt $event): void
    {
        $this->conversations->markDelivery(
            [$event->messageId],
            $event->state,
            $event->failureReason,
            $event->failureCode,
        );
    }

    private function resolveCustomer(WhatsappAccount $account, InboundWhatsAppMessage $event): Customer
    {
        $customer = Customer::query()->where('phone', $event->from)->first();

        if ($customer instanceof Customer) {
            return $customer;
        }

        return Customer::query()->create([
            'name' => $event->profileName ?: $event->from,
            'phone' => $event->from,
            'status' => 'active',
            'store_id' => $account->store_id,
        ]);
    }
}
