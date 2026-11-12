<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The honest state of an integration.
 *
 * These are the only states the dashboard may display. `NotTested` is not a
 * failure and `ConfigurationRequired` is not a connection problem: collapsing
 * them into "disconnected" would send an operator looking for a network fault
 * when a field is simply empty.
 */
enum IntegrationHealthState: string
{
    case Connected = 'connected';
    case Disconnected = 'disconnected';
    case InvalidCredentials = 'invalid_credentials';
    case RateLimited = 'rate_limited';
    case Unavailable = 'service_unavailable';
    case ConfigurationRequired = 'configuration_required';
    case NotTested = 'not_tested';
    case Disabled = 'disabled';

    public function label(): string
    {
        return match ($this) {
            self::Connected => 'Connected',
            self::Disconnected => 'Disconnected',
            self::InvalidCredentials => 'Invalid credentials',
            self::RateLimited => 'Rate limited',
            self::Unavailable => 'Service unavailable',
            self::ConfigurationRequired => 'Configuration required',
            self::NotTested => 'Not tested',
            self::Disabled => 'Disabled',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Connected => 'success',
            self::NotTested => 'neutral',
            self::ConfigurationRequired, self::Disabled => 'warning',
            self::RateLimited => 'info',
            self::Disconnected, self::InvalidCredentials, self::Unavailable => 'danger',
        };
    }
}
