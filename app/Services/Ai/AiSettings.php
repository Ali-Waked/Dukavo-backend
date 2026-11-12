<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Models\Tenant;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\Notifications\NotificationEventCatalog as Events;
use App\Services\Notifications\NotificationIntent;
use App\Support\Tenancy\TenantContext;

/**
 * Platform-level AI policy that is not a provider credential.
 *
 * BYOK ("bring your own key") is the only routing-affecting policy here: it
 * is disabled by default and both switches — the platform feature flag and
 * the tenant's own opt-in — must be on before a merchant's credential is
 * ever preferred over the platform's. A tenant cannot opt into a feature the
 * platform has not enabled.
 */
final class AiSettings
{
    public function __construct(
        private readonly TenantContext $tenants,
        private readonly NotificationDispatcher $notifications,
    ) {}

    /**
     * Whether tenants may configure and route through their own credentials.
     *
     * Disabled by default: enabling it is a deliberate platform-owner
     * decision, made in the AI administration, never a default.
     */
    public function byokEnabled(): bool
    {
        return (bool) config('ai.byok.enabled', false);
    }

    /**
     * Whether this tenant has opted in to using its own credentials.
     *
     * The opt-in is a per-workspace switch in the tenant's AI settings.
     * A tenant that has never touched it is treated as opted out, so nothing
     * changes for existing deployments.
     */
    public function tenantByokOptIn(?Tenant $tenant = null): bool
    {
        if (! $this->byokEnabled()) {
            return false;
        }

        $tenant ??= $this->tenants->tenant();

        if ($tenant === null) {
            return false;
        }

        return (bool) ($tenant->settings['ai_byok_opt_in'] ?? false);
    }

    /**
     * Set or clear the tenant's BYOK opt-in.
     *
     * Refused outright when the platform has not enabled BYOK, so the switch
     * a merchant sees can never enable routing the platform forbids.
     */
    public function setTenantByokOptIn(Tenant $tenant, bool $optIn): bool
    {
        if ($optIn && ! $this->byokEnabled()) {
            return false;
        }

        $settings = is_array($tenant->settings) ? $tenant->settings : [];
        $settings['ai_byok_opt_in'] = $optIn;

        $tenant->forceFill(['settings' => $settings])->save();

        return true;
    }

    /**
     * Whether a tenant AI feature is enabled for the workspace.
     *
     * A tenant may only turn features *off* that the platform has on; the
     * per-feature platform switch remains the ceiling.
     */
    public function tenantFeatureEnabled(Tenant $tenant, string $feature): bool
    {
        $platform = (bool) config('ai.features.'.$feature.'.enabled', true);

        if (! $platform) {
            return false;
        }

        $disabled = $tenant->settings['ai_disabled_features'] ?? [];

        if (! is_array($disabled)) {
            return true;
        }

        return ! in_array($feature, $disabled, true);
    }

    /**
     * Tell the platform owner a provider left rotation.
     *
     * The credential itself never travels: only the provider's name and a
     * reason category. Emission failures are reported, never thrown — an
     * unhealthy provider must not break the request that discovered it.
     */
    public function notifyProviderUnhealthy(string $providerName, string $reason, ?int $providerId = null): void
    {
        try {
            $this->notifications->dispatch(new NotificationIntent(
                eventKey: Events::PLATFORM_AI_PROVIDER_UNHEALTHY,
                dedupeKey: 'ai-provider-unhealthy:'.$providerId,
                data: [
                    'provider' => $providerName,
                    'reason' => $reason,
                ],
            ));
        } catch (\Throwable $exception) {
            report($exception);
        }
    }
}
