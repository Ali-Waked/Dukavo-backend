<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Services\Ai\Contracts\AiProvider;
use App\Services\Ai\Providers\AiProviderFactory;

/**
 * A provider factory that always builds the supplied fake.
 *
 * Endpoint tests care about the HTTP contract, not which vendor serves it. This
 * keeps the real registry in the loop — including its capability checks — while
 * ensuring no test ever reaches the network.
 */
final class FakeAiProviderFactory implements AiProviderFactory
{
    public function __construct(private readonly AiProvider $provider) {}

    /**
     * @return list<string>
     */
    public function drivers(): array
    {
        return ['fake'];
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public function make(string $driver, array $config): AiProvider
    {
        return $this->provider;
    }
}
