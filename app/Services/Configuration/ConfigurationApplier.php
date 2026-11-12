<?php

declare(strict_types=1);

namespace App\Services\Configuration;

use App\Enums\ConfigurationCategory;
use App\Enums\ConfigurationSource;

/**
 * Writes effective database overrides into Laravel's runtime configuration.
 *
 * Boot applies every key once, and the queue lifecycle applies them again
 * before each job so a long-lived worker picks up a change without a restart.
 * Only database-sourced values are written: environment and default values are
 * already what the configuration files loaded, so re-writing them would hide
 * the real precedence from an operator reading `config:show`.
 *
 * Because a database override mutates the same config path the environment
 * wrote, clearing an override must put the previous value back. The applier
 * therefore remembers the first value it saw for each path it changed and
 * restores it when the override disappears. Without that, a cleared setting
 * would leave the last saved value live until the next process restart.
 *
 * A few keys need a second name because the code that consumes them predates
 * the settings catalogue (`app.locale`, `dukavo.name`, the feature block).
 * Those aliases live here, next to the reason they exist, rather than being
 * scattered through services.
 */
final class ConfigurationApplier
{
    /**
     * The value each touched config path had before the first database
     * override, so clearing the override can restore it.
     *
     * @var array<string, mixed>
     */
    private array $originals = [];

    public function __construct(
        private readonly ConfigurationResolver $resolver,
        private readonly ConfigurationRegistry $registry,
    ) {}

    public function apply(): void
    {
        foreach ($this->resolver->all() as $resolved) {
            $this->applyResolved($resolved);
        }

        $this->applyAliases();
    }

    public function applyKey(string $key): void
    {
        $this->applyResolved($this->resolver->get($key));
        $this->applyAliases();
    }

    private function applyResolved(ResolvedConfiguration $resolved): void
    {
        $definition = $resolved->definition;

        if ($definition->category === ConfigurationCategory::Features) {
            // Feature flags are not a framework config path: the catalogue owns
            // their defaults and FeatureFlagService owns their reads. The block
            // is still published so a diagnostic `config:show` tells the truth.
            $flag = str_starts_with($definition->key, 'features.')
                ? substr($definition->key, strlen('features.'))
                : $definition->key;

            $this->writeConfig('dukavo.features.'.$flag, (bool) $resolved->value);

            return;
        }

        if (! $definition->editable() || $definition->configPath === null) {
            return;
        }

        if ($resolved->source !== ConfigurationSource::Database) {
            $this->restoreConfig($definition->configPath);

            return;
        }

        $value = $resolved->value;

        // Laravel's SMTP transport expects a null scheme for "no encryption";
        // the dashboard offers a readable "none" option instead.
        if ($definition->key === 'email.encryption' && $value === 'none') {
            $value = null;
        }

        $this->writeConfig($definition->configPath, $value);
    }

    private function applyAliases(): void
    {
        $name = $this->resolver->get('general.platform_name');
        $this->applyAlias('dukavo.name', $name);

        $locale = $this->resolver->get('localization.default_locale');
        $this->applyAlias('app.locale', $locale);
        $this->applyAlias('dukavo.locales.default', $locale);

        $locales = $this->resolver->get('localization.supported_locales');
        $this->applyAlias('dukavo.locales.supported', $locales);

        $currency = $this->resolver->get('localization.default_currency');
        $this->applyAlias('dukavo.currencies.default', $currency);
    }

    private function applyAlias(string $path, ResolvedConfiguration $resolved): void
    {
        if ($resolved->source === ConfigurationSource::Database) {
            $this->writeConfig($path, $resolved->value);

            return;
        }

        $this->restoreConfig($path);
    }

    /**
     * Remember the pre-override value once, then write the effective value.
     */
    private function writeConfig(string $path, mixed $value): void
    {
        if (! array_key_exists($path, $this->originals)) {
            $this->originals[$path] = config($path);
        }

        config([$path => $value]);
    }

    /**
     * Put back the value the environment or application default supplied.
     */
    private function restoreConfig(string $path): void
    {
        if (! array_key_exists($path, $this->originals)) {
            return;
        }

        config([$path => $this->originals[$path]]);
        unset($this->originals[$path]);
    }
}
