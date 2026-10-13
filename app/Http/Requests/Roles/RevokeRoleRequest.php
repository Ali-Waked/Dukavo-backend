<?php

declare(strict_types=1);

namespace App\Http\Requests\Roles;

/**
 * Revoking a role. The payload is identical to granting one — which user, in
 * which context the route implies — so the contract is inherited rather than
 * restated.
 */
class RevokeRoleRequest extends AssignRoleRequest {}
