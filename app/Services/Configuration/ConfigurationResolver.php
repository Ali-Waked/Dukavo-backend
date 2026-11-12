<?php

declare(strict_types=1);

namespace App\Services\Configuration;

use App\Enums\ConfigurationSource;
use App\Models\PlatformSetting;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Applies the precedence policy to every registered key.
 *
 * Order: a deliberate database override, then the deployment environment as
 * exposed through the definition's config path, then the application default.
 * `EnvironmentOnly` definitions (APP_KEY, database and queue credentials) never
 * consult the database, which is what makes them read-only by construction
 * rather than by a controller's good behaviour.
 *
 * Database rows are cached because every request and every queue job resolves
 * configuration. A write flushes the cache, so an operator's change is visible
 * on the next request; long-lived workers are refreshed by
 * {@see ConfigurationApplier} through the queue lifecycle hooks.
 */
final class ConfigurationResolver
{
    private const string CACHE_KEY = 'platform-configuration:rows:v1';

    /**
     * @var array<string, array{value: ?string, is_secret: bool, secret_hint: ?string}>|null
     */
    private ?array $rows = null;

    public function __construct(
        private readonly ConfigurationRegistry $registry,
        private readonly SecretEncryptionService $secrets,
    ) {}

    /**
     * @return array<string, ResolvedConfiguration>
     */
    public function all(): array
    {
        $rows = $this->rows();
        $resolved = [];

        foreach ($this->registry->all() as $key => $definition) {
            $resolved[$key] = $this->resolve($definition, $rows[$key] ?? null);
        }

        return $resolved;
    }

    public function get(string $key): ResolvedConfiguration
    {
        return $this->resolve($this->registry->get($key), $this->rows()[$key] ?? null);
    }

    /**
     * @return array<string, array{value: ?string, is_secret: bool, secret_hint: ?string}>
     */
    public function rows(): array
    {
        if ($this->rows !== null) {
            return $this->rows;
        }

        try {
            /** @var array<string, array{value: ?string, is_secret: bool, secret_hint: ?string}> $rows */
            $rows = Cache::remember(self::CACHE_KEY, now()->addMinutes(5), function (): array {
                return PlatformSetting::query()
                    ->get(['key', 'value', 'is_secret', 'secret_hint'])
                    ->mapWithKeys(static fn (PlatformSetting $setting): array => [
                        $setting->key => [
                            'value' => $setting->value,
                            'is_secret' => (bool) $setting->is_secret,
                            'secret_hint' => $setting->secret_hint,
                        ],
                    ])
                    ->all();
            });

            return $this->rows = $rows;
        } catch (Throwable) {
            // A boot before the migration, or an unreachable database, must not
            // stop the application from starting: the environment and defaults
            // are a complete configuration on their own.
            return $this->rows = [];
        }
    }

    public function flush(): void
    {
        $this->rows = null;

        Cache::forget(self::CACHE_KEY);
    }

    /**
     * @param  array{value: ?string, is_secret: bool, secret_hint: ?string}|null  $row
     */
    private function resolve(ConfigurationDefinition $definition, ?array $row): ResolvedConfiguration
    {
        if ($row !== null && $row['value'] !== null) {
            $raw = $row['is_secret']
                ? $this->secrets->decrypt($row['value'])
                : $row['value'];

            if ($raw !== null) {
                $value = $definition->cast($raw);

                return new ResolvedConfiguration(
                    definition: $definition,
                    value: $value,
                    source: ConfigurationSource::Database,
                    configured: $definition->isConfigured($value),
                    secretHint: $definition->isSecret()
                        ? ($row['secret_hint'] ?? $this->secrets->hint((string) $raw))
                        : null,
                );
            }
        }

        if ($definition->configPath !== null) {
            $configuredValue = config($definition->configPath);

            if ($configuredValue !== null) {
                $value = $definition->cast($configuredValue);

                return new ResolvedConfiguration(
                    definition: $definition,
                    value: $value,
                    source: $this->equalsDefault($definition, $value)
                        ? ConfigurationSource::Default
                        : ConfigurationSource::Environment,
                    configured: $definition->isConfigured($value),
                    secretHint: $definition->isSecret() && is_string($configuredValue)
                        ? $this->secrets->hint($configuredValue)
                        : null,
                );
            }
        }

        $value = $definition->cast($definition->default);

        return new ResolvedConfiguration(
            definition: $definition,
            value: $value,
            source: ConfigurationSource::Default,
            configured: $definition->isConfigured($value),
            secretHint: $definition->isSecret() && is_string($definition->default)
                ? $this->secrets->hint($definition->default)
                : null,
        );
    }

    private function equalsDefault(ConfigurationDefinition $definition, mixed $value): bool
    {
        $default = $definition->cast($definition->default);

        if (is_array($default) || is_array($value)) {
            return (array) $default === (array) $value;
        }

        return $default === $value;
    }
}
