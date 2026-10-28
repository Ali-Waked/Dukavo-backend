<?php

declare(strict_types=1);

namespace App\Http\Requests\Recovery;

use App\Enums\RecoveryOpportunityStatus;
use App\Enums\RecoveryReason;
use App\Http\Requests\ApiRequest;
use Illuminate\Validation\Rule;

class IndexRecoveryOpportunitiesRequest extends ApiRequest
{
    protected function prepareForValidation(): void
    {
        foreach (['search', 'status', 'reason', 'assigned_to', 'store_id'] as $key) {
            if ($this->input($key) === '') {
                $this->merge([$key => null]);
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $statuses = array_column(RecoveryOpportunityStatus::cases(), 'value');

        return [
            'search' => ['sometimes', 'nullable', 'string', 'max:120'],
            'status' => ['sometimes', 'nullable', Rule::in(['open', ...$statuses])],
            'reason' => ['sometimes', 'nullable', Rule::enum(RecoveryReason::class)],
            'assigned_to' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'store_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'mine' => ['sometimes', 'boolean'],
            'unassigned' => ['sometimes', 'boolean'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
