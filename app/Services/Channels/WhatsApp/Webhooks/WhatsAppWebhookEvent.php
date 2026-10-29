<?php

declare(strict_types=1);

namespace App\Services\Channels\WhatsApp\Webhooks;

interface WhatsAppWebhookEvent
{
    public function eventKey(): string;

    public function eventType(): string;
}
