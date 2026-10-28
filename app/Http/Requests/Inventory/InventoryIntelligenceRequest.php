<?php

declare(strict_types=1);

namespace App\Http\Requests\Inventory;

use App\Http\Requests\ApiRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Validator;

/**
 * Shared filters for the inventory intelligence endpoints.
 *
 * The date range describes the history used for demand, stockouts and slow
 * moving analysis. Current on-hand numbers are always live; a historical range
 * never rewrites the stock level shown today.
 */
class InventoryIntelligenceRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'store_id' => ['nullable', 'integer', $this->tenantExists('stores')],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'until' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'status' => ['nullable', $this->oneOf(['all', 'low', 'out', 'slow', 'reorder'])],
            'q' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            [$from, $until] = $this->range();

            if ($from->diffInDays($until) + 1 > (int) config('inventory.analysis.max_days', 366)) {
                $validator->errors()->add('from', 'The analysis range may not exceed '.(int) config('inventory.analysis.max_days', 366).' days.');
            }

            if ($until->toDateString() > Carbon::now()->toDateString()) {
                $validator->errors()->add('until', 'The analysis range cannot end in the future.');
            }
        });
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    public function range(): array
    {
        $until = $this->filled('until')
            ? Carbon::parse($this->string('until')->toString())->endOfDay()
            : Carbon::now()->endOfDay();

        $from = $this->filled('from')
            ? Carbon::parse($this->string('from')->toString())->startOfDay()
            : $until->copy()->subDays((int) config('inventory.analysis.default_days', 28) - 1)->startOfDay();

        return [$from, $until];
    }

    public function statusFilter(): string
    {
        return $this->string('status', 'all')->toString();
    }

    public function searchTerm(): ?string
    {
        $term = trim($this->string('q')->toString());

        return $term === '' ? null : $term;
    }
}
