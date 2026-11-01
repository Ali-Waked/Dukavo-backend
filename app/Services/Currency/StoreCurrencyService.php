<?php

declare(strict_types=1);

namespace App\Services\Currency;

use App\Enums\BundleDiscountType;
use App\Exceptions\BusinessRuleViolation;
use App\Models\Bundle;
use App\Models\CurrencyConversion;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Changes a store's base currency as one controlled financial operation.
 *
 * The scope of the change is deliberate:
 *
 *  - Current catalogue prices (products, variants) and costs are converted,
 *    because they describe what the merchant charges today.
 *  - Fixed-amount bundle discounts are converted; percentage discounts are
 *    currency-independent and are left alone.
 *  - Orders, order items, payments and refunds are historical transactions and
 *    are never rewritten. Their original currency and amounts stay intact, and
 *    reporting converts them with the stored rate snapshot instead.
 *
 * Every attempt is written to `currency_conversions`. The price updates and the
 * store's currency update happen in one transaction under a row lock, so a
 * failure leaves the store exactly as it was.
 */
class StoreCurrencyService
{
    public function __construct(
        private readonly TenantContext $tenants,
        private readonly CurrencyCatalog $currencies,
        private readonly ExchangeRateService $exchange,
    ) {}

    /**
     * What a conversion would do, without touching any row.
     *
     * @return array<string, mixed>
     */
    public function preview(Store $store, string $target): array
    {
        $target = strtoupper($target);
        $this->assertSupported($target);

        if ($target === strtoupper((string) $store->currency)) {
            return [
                'store_id' => $store->getKey(),
                'from_currency' => strtoupper((string) $store->currency),
                'to_currency' => $target,
                'rate' => 1.0,
                'rate_source' => 'identity',
                'rate_fetched_at' => CarbonImmutable::now()->toIso8601String(),
                'products' => 0,
                'variants' => 0,
                'bundles' => 0,
                'sample' => [],
                'noop' => true,
            ];
        }

        $rate = $this->exchange->rate((string) $store->currency, $target);
        $products = $this->productsQuery($store);
        $productIds = (clone $products)->select('id');
        $sample = (clone $products)
            ->orderBy('name')
            ->limit(5)
            ->get(['id', 'name', 'price', 'cost'])
            ->map(fn (Product $product): array => [
                'id' => $product->getKey(),
                'name' => $product->name,
                'price_before' => (float) $product->price,
                'price_after' => $this->convertAmount((float) $product->price, $rate->rate, $target),
                'cost_before' => (float) $product->cost,
                'cost_after' => $this->convertAmount((float) $product->cost, $rate->rate, $target),
            ])
            ->all();

        return [
            'store_id' => $store->getKey(),
            'from_currency' => $rate->from,
            'to_currency' => $rate->to,
            'rate' => $rate->rate,
            'rate_source' => $rate->source,
            'rate_fetched_at' => $rate->fetchedAt->toIso8601String(),
            'products' => (clone $products)->count(),
            'variants' => ProductVariant::query()->whereIn('product_id', $productIds)->count(),
            'bundles' => $this->bundlesQuery($store)->count(),
            'sample' => $sample,
            'noop' => false,
        ];
    }

    /**
     * Execute the conversion atomically.
     *
     * @param  array<string, mixed>  $context  Extra audit context (IP, user agent).
     */
    public function convert(
        Store $store,
        string $target,
        string $idempotencyKey,
        ?int $requestedBy = null,
        array $context = [],
    ): CurrencyConversion {
        $target = strtoupper($target);
        $this->assertSupported($target);

        $existing = CurrencyConversion::query()
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if ($existing !== null) {
            if ($existing->status === 'completed') {
                return $existing;
            }

            throw BusinessRuleViolation::because(
                'A conversion with this request key is already in progress or has failed.',
                ['idempotency_key' => $idempotencyKey, 'status' => $existing->status],
                'currency_conversion_duplicate',
            );
        }

        $from = strtoupper((string) $store->currency);

        if ($target === $from) {
            throw BusinessRuleViolation::because(
                'The store already uses this currency.',
                ['currency' => $target],
                'currency_unchanged',
            );
        }

        // Fetch the quote before opening the transaction: a slow provider must
        // not hold a row lock while an external request is in flight.
        $rate = $this->exchange->rate($from, $target);

        $conversion = CurrencyConversion::query()->create([
            'tenant_id' => $store->tenant_id,
            'store_id' => $store->getKey(),
            'from_currency' => $from,
            'to_currency' => $target,
            'exchange_rate' => $rate->rate,
            'rate_source' => $rate->source,
            'rate_fetched_at' => $rate->fetchedAt,
            'status' => 'processing',
            'idempotency_key' => $idempotencyKey,
            'requested_by' => $requestedBy,
        ]);

        try {
            DB::transaction(function () use ($store, $target, $rate, $conversion): void {
                /** @var Store $locked */
                $locked = Store::query()->whereKey($store->getKey())->lockForUpdate()->firstOrFail();

                if (strtoupper((string) $locked->currency) !== $rate->from) {
                    throw BusinessRuleViolation::because(
                        'The store currency changed while the conversion was being prepared.',
                        ['expected' => $rate->from, 'actual' => $locked->currency],
                        'currency_conversion_conflict',
                    );
                }

                $products = 0;
                $variants = 0;
                $bundles = 0;

                $this->productsQuery($locked)
                    ->orderBy('id')
                    ->chunkById(200, function ($rows) use ($target, $rate, &$products): void {
                        foreach ($rows as $product) {
                            $product->forceFill([
                                'price' => $this->convertAmount((float) $product->price, $rate->rate, $target),
                                'cost' => $this->convertAmount((float) $product->cost, $rate->rate, $target),
                                'currency' => $target,
                            ])->save();
                            $products++;
                        }
                    });

                $productIds = $this->productsQuery($locked)->select('id');

                ProductVariant::query()
                    ->whereIn('product_id', $productIds)
                    ->orderBy('id')
                    ->chunkById(200, function ($rows) use ($target, $rate, &$variants): void {
                        foreach ($rows as $variant) {
                            $variant->forceFill([
                                'price' => $this->convertAmount((float) $variant->price, $rate->rate, $target),
                                'cost' => $this->convertAmount((float) $variant->cost, $rate->rate, $target),
                            ])->save();
                            $variants++;
                        }
                    });

                $this->bundlesQuery($locked)
                    ->orderBy('id')
                    ->chunkById(200, function ($rows) use ($target, $rate, &$bundles): void {
                        foreach ($rows as $bundle) {
                            $bundle->forceFill([
                                'discount_value' => $this->convertAmount((float) $bundle->discount_value, $rate->rate, $target),
                                'currency' => $target,
                            ])->save();
                            $bundles++;
                        }
                    });

                $locked->forceFill([
                    'currency' => $target,
                    'currency_decimals' => $this->currencies->decimals($target),
                    'currency_updated_at' => now(),
                ])->save();

                $conversion->forceFill([
                    'status' => 'completed',
                    'products_converted' => $products,
                    'variants_converted' => $variants,
                    'bundles_converted' => $bundles,
                    'completed_at' => now(),
                ])->save();
            });
        } catch (\Throwable $exception) {
            $conversion->forceFill([
                'status' => 'failed',
                'failure_reason' => mb_substr($exception->getMessage(), 0, 1000),
            ])->save();

            report($exception);

            throw $exception;
        }

        $store->refresh();

        return $conversion->refresh();
    }

    /**
     * The most recent stored rate between two currencies, if one exists.
     *
     * Historical reporting uses this instead of a live quote: the number that
     * was actually applied is the only defensible rate for an old order.
     */
    public function storedRate(string $from, string $to): ?ExchangeRate
    {
        $from = strtoupper($from);
        $to = strtoupper($to);

        if ($from === $to) {
            return new ExchangeRate($from, $to, 1.0, 'identity', CarbonImmutable::now());
        }

        $conversion = CurrencyConversion::query()
            ->where('status', 'completed')
            ->where(fn ($query) => $query
                ->where(fn ($direct) => $direct->where('from_currency', $from)->where('to_currency', $to))
                ->orWhere(fn ($inverse) => $inverse->where('from_currency', $to)->where('to_currency', $from)))
            ->orderByDesc('completed_at')
            ->first();

        if ($conversion === null) {
            return null;
        }

        $rate = (float) $conversion->exchange_rate;

        if ($conversion->from_currency !== $from) {
            $rate = $rate > 0 ? 1 / $rate : 0;
        }

        return new ExchangeRate(
            from: $from,
            to: $to,
            rate: $rate,
            source: (string) $conversion->rate_source,
            fetchedAt: $conversion->rate_fetched_at ?? $conversion->created_at,
        );
    }

    /**
     * Recent conversions for a store, newest first.
     *
     * @return list<array<string, mixed>>
     */
    public function history(Store $store, int $limit = 20): array
    {
        return CurrencyConversion::query()
            ->where('store_id', $store->getKey())
            ->orderByDesc('id')
            ->limit(max(1, min($limit, 100)))
            ->get()
            ->map(fn (CurrencyConversion $conversion): array => [
                'id' => $conversion->getKey(),
                'from_currency' => $conversion->from_currency,
                'to_currency' => $conversion->to_currency,
                'exchange_rate' => (float) $conversion->exchange_rate,
                'rate_source' => $conversion->rate_source,
                'rate_fetched_at' => $conversion->rate_fetched_at?->toIso8601String(),
                'status' => $conversion->status,
                'products_converted' => $conversion->products_converted,
                'variants_converted' => $conversion->variants_converted,
                'bundles_converted' => $conversion->bundles_converted,
                'failure_reason' => $conversion->failure_reason,
                'completed_at' => $conversion->completed_at?->toIso8601String(),
                'created_at' => $conversion->created_at?->toIso8601String(),
            ])
            ->all();
    }

    /**
     * Products whose current price belongs to this store.
     *
     * A product with no store is tenant-wide; it follows the default store's
     * currency, which is the only store that can represent the whole catalogue.
     */
    private function productsQuery(Store $store): Builder
    {
        return Product::query()
            ->where(function ($query) use ($store): void {
                $query->where('store_id', $store->getKey());

                if ($store->is_default) {
                    $query->orWhereNull('store_id');
                }
            });
    }

    private function bundlesQuery(Store $store): Builder
    {
        return Bundle::query()
            ->where('store_id', $store->getKey())
            ->where('discount_type', BundleDiscountType::Fixed);
    }

    private function convertAmount(float $amount, float $rate, string $target): float
    {
        return $this->currencies->round($amount * $rate, $target);
    }

    private function assertSupported(string $code): void
    {
        if (! $this->currencies->supports($code)) {
            throw BusinessRuleViolation::because(
                'This currency is not supported.',
                ['currency' => $code],
                'currency_unsupported',
            );
        }
    }
}
