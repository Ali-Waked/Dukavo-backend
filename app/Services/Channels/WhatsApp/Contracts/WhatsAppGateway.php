<?php

declare(strict_types=1);

namespace App\Services\Channels\WhatsApp\Contracts;

use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\WhatsappAccount;
use App\Services\Channels\WhatsApp\Exceptions\WhatsAppDeliveryFailed;

/**
 * Boundary to the WhatsApp Cloud API.
 *
 * Everything the rest of the application knows about WhatsApp lives behind this
 * contract, so the provider can be swapped, faked in tests, or replaced with
 * another channel without touching conversation or inbox logic.
 */
interface WhatsAppGateway
{
    /**
     * Send a text message and return the provider's message id.
     *
     * @throws WhatsAppDeliveryFailed
     */
    public function sendText(Conversation $conversation, string $body, ?string $externalId = null): string;

    /**
     * Send an already prepared message template.
     *
     * @param  array<string, mixed>  $parameters
     *
     * @throws WhatsAppDeliveryFailed
     */
    public function sendTemplate(Conversation $conversation, string $templateName, string $languageCode, array $parameters = []): string;

    /**
     * Verify that the stored credentials still work, returning the provider's
     * display name for the number.
     *
     * @throws WhatsAppDeliveryFailed
     */
    public function verifyAccount(WhatsappAccount $account): string;

    /**
     * Persist a delivery receipt for a message the provider reported on.
     */
    public function recordStatus(ConversationMessage $message, string $status, ?string $error = null): void;
}
