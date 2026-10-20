<?php

declare(strict_types=1);

namespace App\Http\Requests\Ai;

use App\Http\Requests\ApiRequest;

/**
 * Tenant insights are always scoped to the resolved workspace; a store may be
 * selected to narrow the figures, and the rule confines that lookup to the
 * active tenant so a foreign id is rejected at validation time.
 */
class TenantInsightsRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'store_id' => ['nullable', 'integer', $this->tenantExists('stores')],
        ];
    }
}
