<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Who owns a provider credential.
 *
 * Platform credentials are configured by the Dukavo team and are available to
 * every workspace within platform policy. Tenant credentials are a merchant's
 * own (BYOK), isolated to their workspace and only used when the merchant has
 * explicitly opted in and the platform has enabled the feature.
 *
 * A merchant can never see, test or route through a credential that is not
 * theirs; the routing engine filters by owner before anything is built.
 */
enum AiCredentialOwner: string
{
    case Platform = 'platform';
    case Tenant = 'tenant';
}
