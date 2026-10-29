<?php

declare(strict_types=1);

namespace App\Services\Ai\Providers;

use App\Services\Ai\Contracts\AiProvider;
use App\Services\Ai\Exceptions\AiUnavailable;
use App\Services\Ai\Support\AiCapability;
use Illuminate\Contracts\Config\Repository;

/**
 * Decides which provider serves a request.
 *
 * Providers are not interchangeable, so the choice is made against a stated
 * requirement rather than by hope. A use case asks for the capabilities it
 * needs; the registry returns the primary provider when it can serve them, then
 * the configured fallbacks in order, and refuses when none can.
 *
 * Refusing is a first-class outcome. A deployment whose only provider cannot
 * do structured output gets a clear "unavailable" for the affected features
 * instead of a stream of malformed answers.
 */
final class ProviderRegistry
{
    public function __construct(
        private readonly Repository $config,
        private readonly AiProviderFactory $factory,
    ) {}

    /**
     * The configured primary provider, whatever its capabilities.
     *
     * It is returned even when it has no credential: the caller then gets the
     * precise "not configured" failure instead of a confusing "no provider
     * supports this".
     */
    public function primary(): AiProvider
    {
        return $this->build((string) $this->config->get('ai.provider', 'xai'));
    }

    /**
     * Every provider that has a usable credential.
     *
     * @return list<AiProvider>
     */
    public function configured(): array
    {
        $providers = [];

        foreach ($this->providerKeys() as $key) {
            $provider = $this->build($key);

            if ($provider->isConfigured()) {
                $providers[] = $provider;
            }
        }

        return $providers;
    }

    /**
     * A provider that satisfies every required capability.
     *
     * @throws AiUnavailable when no configured provider can serve the request.
     */
    public function for(AiCapability ...$required): AiProvider
    {
        $candidates = [$this->primary(), ...$this->configured()];

        foreach ($candidates as $candidate) {
            if (! $candidate->isConfigured()) {
                continue;
            }

            if ($this->satisfies($candidate, $required)) {
                return $candidate;
            }
        }

        throw $this->unsupported($required);
    }

    /**
     * Whether any configured provider can serve a request of this shape.
     */
    public function canServe(AiCapability ...$required): bool
    {
        try {
            $this->for(...$required);

            return true;
        } catch (AiUnavailable) {
            return false;
        }
    }

    /**
     * A description of the current AI setup that is safe to show a client.
     *
     * It names the provider and states which capabilities are available. It
     * never contains a credential, an endpoint or a key.
     *
     * @return array{provider: string, configured: bool, capabilities: list<string>}
     */
    public function describe(): array
    {
        $primary = $this->primary();

        return [
            'provider' => $primary->provider(),
            'configured' => $primary->isConfigured(),
            'capabilities' => array_map(
                static fn (AiCapability $capability): string => $capability->value,
                $primary->capabilities(),
            ),
        ];
    }

    /**
     * The provider keys to consider, primary first, then fallbacks in order.
     *
     * @return list<string>
     */
    private function providerKeys(): array
    {
        $primary = (string) $this->config->get('ai.provider', 'xai');
        $fallbacks = array_values(array_filter(
            (array) $this->config->get('ai.fallback_providers', []),
            'is_string',
        ));

        return array_values(array_unique([$primary, ...$fallbacks]));
    }

    /**
     * @param  list<AiCapability>  $required
     */
    private function satisfies(AiProvider $provider, array $required): bool
    {
        foreach ($required as $capability) {
            $satisfied = false;

            foreach ($provider->capabilities() as $supported) {
                if ($supported->satisfies($capability)) {
                    $satisfied = true;

                    break;
                }
            }

            if (! $satisfied) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<AiCapability>  $required
     */
    private function unsupported(array $required): AiUnavailable
    {
        $names = array_map(static fn (AiCapability $capability): string => $capability->value, $required);

        if ($names === []) {
            return AiUnavailable::notConfigured();
        }

        return AiUnavailable::unsupportedCapability($names);
    }

    private function build(string $key): AiProvider
    {
        $providers = (array) $this->config->get('ai.providers', []);
        $block = (array) ($providers[$key] ?? []);

        $driver = (string) ($block['driver'] ?? $key);

        return $this->factory->make($driver, $block);
    }
}
