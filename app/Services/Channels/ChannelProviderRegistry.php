<?php

declare(strict_types=1);

namespace App\Services\Channels;

use App\Services\Channels\Contracts\ChannelConnector;
use App\Services\Channels\Exceptions\ChannelDeliveryFailed;

/**
 * The map from a channel key to its connector.
 *
 * Connectors are registered once (see AppServiceProvider) and resolved by key
 * everywhere else. Nothing in the application may `new` a provider adapter, so
 * a test can substitute a fake connector and production can add a channel
 * without touching callers.
 */
final class ChannelProviderRegistry
{
    /** @var array<string, ChannelConnector> */
    private array $connectors = [];

    /**
     * @param  iterable<ChannelConnector>  $connectors
     */
    public function __construct(iterable $connectors = [])
    {
        foreach ($connectors as $connector) {
            $this->register($connector);
        }
    }

    public function register(ChannelConnector $connector): void
    {
        $this->connectors[$connector->channel()] = $connector;
    }

    /**
     * @throws ChannelDeliveryFailed
     */
    public function for(string $channel): ChannelConnector
    {
        return $this->connectors[$channel]
            ?? throw ChannelDeliveryFailed::notConfigured($channel);
    }

    public function has(string $channel): bool
    {
        return isset($this->connectors[$channel]);
    }

    /**
     * @return array<string, ChannelConnector>
     */
    public function all(): array
    {
        return $this->connectors;
    }
}
