<?php

declare(strict_types=1);

namespace App\Services\Configuration;

use App\Enums\ConfigurationCategory;
use App\Models\User;
use InvalidArgumentException;

/**
 * Reads and writes platform availability flags.
 *
 * The flag names a capability, not a customer's plan. A merchant still needs
 * its own permission and configuration; disabling a flag removes the capability
 * for everyone, which is the platform-level emergency switch.
 *
 * Reads go through the resolver (so a database override is honoured) and fall
 * back to the catalogue default when nothing has been written.
 */
final class FeatureFlagService
{
    public function __construct(
        private readonly ConfigurationResolver $resolver,
        private readonly PlatformConfigurationService $settings,
    ) {}

    public function enabled(string $key): bool
    {
        if (! FeatureCatalogue::has($key)) {
            throw new InvalidArgumentException(sprintf('Unknown platform feature flag [%s].', $key));
        }

        $resolved = $this->resolver->get('features.'.$key);

        return (bool) $resolved->value;
    }

    public function disabled(string $key): bool
    {
        return ! $this->enabled($key);
    }

    /**
     * @return list<array{key: string, label: string, label_ar: string, description: string, description_ar: string, group: string, enabled: bool, default: bool, source: string}>
     */
    public function all(): array
    {
        $flags = [];

        foreach (FeatureCatalogue::flags() as $flag) {
            $resolved = $this->resolver->get('features.'.$flag['key']);

            $flags[] = [
                'key' => $flag['key'],
                'label' => $flag['label'],
                'label_ar' => $flag['labelAr'],
                'description' => $flag['description'],
                'description_ar' => $flag['descriptionAr'],
                'group' => $flag['group'],
                'enabled' => (bool) $resolved->value,
                'default' => $flag['default'],
                'source' => $resolved->source->value,
            ];
        }

        return $flags;
    }

    public function set(string $key, bool $enabled, ?User $actor = null): void
    {
        if (! FeatureCatalogue::has($key)) {
            throw new InvalidArgumentException(sprintf('Unknown platform feature flag [%s].', $key));
        }

        $this->settings->put('features.'.$key, $enabled, $actor);
    }

    public function category(): ConfigurationCategory
    {
        return ConfigurationCategory::Features;
    }
}
