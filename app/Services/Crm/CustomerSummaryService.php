<?php

declare(strict_types=1);

namespace App\Services\Crm;

use App\Models\Customer;
use App\Models\CustomerSummary;
use App\Models\User;
use App\Services\Ai\Features\CustomerSummaryAssistant;
use App\Support\Tenancy\TenantContext;

class CustomerSummaryService
{
    public function __construct(
        private readonly TenantContext $tenants,
        private readonly CustomerSummaryAssistant $assistant,
    ) {}

    public function getOrCreate(Customer $customer): CustomerSummary
    {
        $tenant = $this->tenants->required();

        $summary = CustomerSummary::query()->firstOrCreate(
            [
                'tenant_id' => $tenant->getKey(),
                'customer_id' => $customer->getKey(),
            ],
            [
                'summary' => '',
                'highlights' => [],
                'risks' => [],
                'opportunities' => [],
                'source_context' => [],
                'is_edited' => false,
            ]
        );

        return $summary;
    }

    /**
     * Generate a fresh AI draft and only persist it once the model succeeded.
     *
     * A provider failure propagates as the AI feature's own exception, so the
     * previously stored (possibly staff-corrected) summary is left untouched.
     */
    public function refresh(Customer $customer, User $actor): CustomerSummary
    {
        $result = $this->assistant->summarise($customer, $actor);

        $summary = $this->getOrCreate($customer);

        $summary->update([
            'summary' => $result['summary'],
            'highlights' => $result['highlights'],
            'risks' => $result['risks'],
            'opportunities' => $result['opportunities'],
            'source_context' => $result['source_context'],
            'generated_by' => $actor->getKey(),
            'model' => $result['usage']['model'] ?: null,
            'refreshed_at' => now(),
            'is_edited' => false,
            'edited_by' => null,
            'edited_at' => null,
        ]);

        return $summary->fresh();
    }

    public function update(CustomerSummary $summary, array $attributes, ?User $actor = null): CustomerSummary
    {
        $update = $attributes;
        if (array_key_exists('summary', $attributes)) {
            $update['is_edited'] = true;
            $update['edited_by'] = $actor?->getKey();
            $update['edited_at'] = now();
        }

        $summary->update($update);

        return $summary->fresh();
    }
}
