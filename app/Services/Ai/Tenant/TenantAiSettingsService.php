<?php

declare(strict_types=1);

namespace App\Services\Ai\Tenant;

use App\Models\Tenant;
use App\Services\Ai\AiSettings;

/**
 * The workspace's own AI settings: which features it runs and whether it
 * brings its own provider credentials.
 *
 * Everything a merchant changes here can only *narrow* what the platform
 * already allows: a feature the platform has disabled cannot be switched
 * on from a workspace, and BYOK requires the platform flag before the
 * opt-in means anything.
 */
final class TenantAiSettingsService
{
    public function __construct(private readonly AiSettings $policy) {}

    /**
     * @return array{
     *     ai_enabled: bool,
     *     features: list<array{feature: string, enabled: bool, platform_enabled: bool}>,
     *     byok: array{platform_enabled: bool, opted_in: bool},
     * }
     */
    public function overview(Tenant $tenant): array
    {
        return [
            'ai_enabled' => (bool) config('ai.enabled'),
            'features' => $this->features($tenant),
            'byok' => [
                'platform_enabled' => $this->policy->byokEnabled(),
                'opted_in' => $this->policy->tenantByokOptIn($tenant),
            ],
        ];
    }

    /**
     * The feature list a merchant may toggle, with the platform ceiling
     * visible so a disabled feature reads as platform policy, not a bug.
     *
     * @return list<array{feature: string, enabled: bool, platform_enabled: bool}>
     */
    public function features(Tenant $tenant): array
    {
        $configured = (array) config('ai.features');

        $features = [];

        foreach (array_keys($configured) as $feature) {
            $features[] = [
                'feature' => (string) $feature,
                'enabled' => $this->policy->tenantFeatureEnabled($tenant, (string) $feature),
                'platform_enabled' => (bool) config('ai.features.'.$feature.'.enabled', false),
            ];
        }

        return $features;
    }

    /**
     * Set the workspace's feature switches.
     *
     * Unknown features are refused rather than ignored, so a stale client
     * fails loudly instead of silently not working. Enabling a feature the
     * platform has off is refused the same way — the platform switch is
     * the ceiling, and a workspace that never had a feature cannot switch
     * it back on from here.
     *
     * @param  array<string, bool>  $features
     * @return list<string> The features the workspace has disabled.
     */
    public function syncFeatures(Tenant $tenant, array $features): array
    {
        $configured = (array) config('ai.features');

        $disabled = [];

        foreach ($features as $feature => $enabled) {
            if (! is_string($feature) || ! array_key_exists($feature, $configured)) {
                throw new \InvalidArgumentException(sprintf('Unknown AI feature [%s].', $feature));
            }

            if ($enabled && ! (bool) config('ai.features.'.$feature.'.enabled', false)) {
                throw new \InvalidArgumentException(
                    sprintf('The [%s] feature is disabled by the platform and cannot be enabled by a workspace.', $feature),
                );
            }

            if (! $enabled) {
                $disabled[] = $feature;
            }
        }

        // Features the merchant left out of the payload keep their current
        // state: the payload is a patch, not a whole-list replacement.
        $current = $tenant->settings['ai_disabled_features'] ?? [];

        if (is_array($current)) {
            foreach ($current as $feature) {
                if (is_string($feature) && array_key_exists($feature, $configured) && ! array_key_exists($feature, $features)) {
                    $disabled[] = $feature;
                }
            }
        }

        $disabled = array_values(array_unique($disabled));

        $settings = is_array($tenant->settings) ? $tenant->settings : [];
        $settings['ai_disabled_features'] = $disabled;

        $tenant->forceFill(['settings' => $settings])->save();

        return $disabled;
    }

    /**
     * Set the workspace's BYOK opt-in. Returns false when the platform has
     * not enabled BYOK, in which case nothing changed.
     */
    public function setByokOptIn(Tenant $tenant, bool $optIn): bool
    {
        return $this->policy->setTenantByokOptIn($tenant, $optIn);
    }
}
