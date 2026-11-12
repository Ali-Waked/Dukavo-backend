<?php

declare(strict_types=1);

namespace App\Services\Ai\Providers\Tts;

/**
 * The hosted OpenAI speech endpoint.
 *
 * This adapter is paid. It reports itself unavailable unless the deployment has
 * explicitly authorised paid providers *and* supplied a key, so a free-first
 * installation can never start billing because a config value was left blank.
 */
final class OpenAiTtsProvider extends OpenAiCompatibleTtsProvider
{
    public function provider(): string
    {
        return 'openai';
    }

    public function isPaid(): bool
    {
        return true;
    }

    protected function requiresKey(): bool
    {
        return true;
    }

    public function isConfigured(): bool
    {
        return (bool) ($this->config['allow_paid'] ?? false) && parent::isConfigured();
    }
}
