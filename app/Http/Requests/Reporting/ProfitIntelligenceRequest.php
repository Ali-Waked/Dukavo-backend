<?php

declare(strict_types=1);

namespace App\Http\Requests\Reporting;

use App\Http\Requests\ApiRequest;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Validator;

/**
 * Filters for the profit report.
 *
 * Currency is a filter rather than an assumption: amounts in different
 * currencies are never added together, so a single report is always expressed
 * in one currency.
 */
class ProfitIntelligenceRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'store_id' => ['nullable', 'integer', $this->tenantExists('stores')],
            'currency' => ['nullable', 'string', 'size:3', 'regex:/^[A-Za-z]{3}$/'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'until' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'product_limit' => ['nullable', 'integer', 'min:1', 'max:'.(int) config('profit.products.max_limit', 100)],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            [$from, $until] = $this->range();
            $maxDays = (int) config('profit.analysis.max_days', 366);

            if ($from->diffInDays($until) + 1 > $maxDays) {
                $validator->errors()->add('from', 'The reporting range may not exceed '.$maxDays.' days.');
            }

            if ($until->toDateString() > Carbon::now()->toDateString()) {
                $validator->errors()->add('until', 'The reporting range cannot end in the future.');
            }
        });
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    public function range(): array
    {
        $until = $this->filled('until')
            ? Carbon::parse($this->string('until')->toString())->startOfDay()
            : Carbon::now()->startOfDay();

        $from = $this->filled('from')
            ? Carbon::parse($this->string('from')->toString())->startOfDay()
            : $until->copy()->subDays((int) config('profit.analysis.default_days', 30) - 1)->startOfDay();

        return [$from, $until];
    }

    public function currency(): string
    {
        $submitted = strtoupper(trim($this->string('currency')->toString()));

        if ($submitted !== '') {
            return $submitted;
        }

        return strtoupper((string) app(TenantContext::class)->required()->currency);
    }

    public function productLimit(): int
    {
        return min(
            max(1, $this->integer('product_limit', (int) config('profit.products.default_limit', 25))),
            (int) config('profit.products.max_limit', 100),
        );
    }
}
