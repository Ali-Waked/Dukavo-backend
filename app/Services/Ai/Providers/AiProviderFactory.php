<?php

declare(strict_types=1);

namespace App\Services\Ai\Providers;

use App\Services\Ai\Contracts\AiProvider;

/**
 * Builds a provider adapter from a configuration block.
 *
 * The registry needs to turn `['driver' => 'xai', 'api_key' => '…']` into a
 * live adapter. Keeping that behind a contract means the registry's own logic
 * — capability matching, fallback order, availability — can be exercised in
 * tests with a fake factory, without a network, a credential or a real
 * provider being involved.
 */
interface AiProviderFactory
{
    /**
     * The driver keys this factory can build.
     *
     * @return list<string>
     */
    public function drivers(): array;

    /**
     * Build a provider from a configuration block.
     *
     * @param  array<string, mixed>  $config
     */
    public function make(string $driver, array $config): AiProvider;
}
