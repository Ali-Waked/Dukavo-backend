<?php

declare(strict_types=1);

namespace App\Services\Reporting;

use App\Enums\NotificationSeverity;
use App\Models\Store;
use App\Models\Tenant;
use App\Services\Currency\CurrencyCatalog;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\Notifications\NotificationEventCatalog as Events;
use App\Services\Notifications\NotificationIntent;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Builds and dispatches the scheduled business reports.
 *
 * The report data comes from {@see ProfitIntelligenceService}, which reads only
 * figures Dukavo actually stores. No rate is guessed and no currency is mixed:
 * the tenant's own reporting currency is used, and missing values stay null
 * instead of being rendered as a confident zero.
 *
 * The caller must already have established the tenant context. Keeping that
 * decision in the command makes it impossible for a scheduled report to read
 * one merchant's orders while addressing another merchant's recipients.
 */
final class ScheduledReportService
{
    public function __construct(
        private readonly ProfitIntelligenceService $reports,
        private readonly CurrencyCatalog $currencies,
        private readonly NotificationDispatcher $notifications,
    ) {}

    /**
     * The event key for a report period.
     */
    public function eventKey(string $period): string
    {
        return match ($period) {
            'weekly' => Events::TENANT_REPORT_WEEKLY,
            'monthly' => Events::TENANT_REPORT_MONTHLY,
            'performance' => Events::TENANT_REPORT_PERFORMANCE,
            default => throw new InvalidArgumentException(sprintf('Unknown report period [%s].', $period)),
        };
    }

    /**
     * The date range a period covers.
     *
     * Weekly and performance reports are rolling windows ending today. A
     * monthly report covers the previous completed calendar month, which is
     * what a report delivered on the first of the month is expected to describe.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function bounds(string $period, CarbonImmutable $now): array
    {
        return match ($period) {
            'weekly' => [$now->subDays(6)->startOfDay(), $now->startOfDay()],
            'monthly' => [$now->startOfMonth()->subMonthNoOverflow()->startOfDay(), $now->startOfMonth()->subDay()->endOfDay()],
            'performance' => [$now->subDays(29)->startOfDay(), $now->startOfDay()],
            default => throw new InvalidArgumentException(sprintf('Unknown report period [%s].', $period)),
        };
    }

    /**
     * Build the data a report notification carries.
     *
     * @return array<string, mixed>
     */
    public function build(Tenant $tenant, string $period, ?CarbonImmutable $now = null): array
    {
        // The report window follows the merchant's own calendar, not the
        // server's. A workspace in Riyadh must not have its "yesterday" cut off
        // three hours early because the queue runs in UTC.
        $now ??= CarbonImmutable::now($tenant->timezone ?: (string) config('app.timezone', 'UTC'));
        [$from, $until] = $this->bounds($period, $now);

        $store = Store::query()
            ->where('is_default', true)
            ->first()
            ?? Store::query()->first();

        $currency = strtoupper((string) ($store?->currency ?: $tenant->currency ?: config('dukavo.currencies.default', 'USD')));

        $overview = $this->reports->overview($store, $currency, $from, $until, 5);
        $summary = is_array($overview['summary'] ?? null) ? $overview['summary'] : [];
        $orders = (int) ($summary['recognized_orders'] ?? 0);

        $products = array_map(fn (array $product): array => [
            'name' => (string) ($product['name'] ?? ''),
            'units' => (int) ($product['units'] ?? 0),
            'net_sales' => $product['net_sales'] ?? null,
        ], is_array($overview['products'] ?? null) ? $overview['products'] : []);

        return [
            'period' => $from->toDateString().' – '.$until->toDateString(),
            'currency' => $currency,
            'orders' => $orders,
            'net_revenue' => $this->money($summary['net_sales'] ?? null, $currency),
            'has_data' => $orders > 0,
            'summary' => [
                'orders' => $orders,
                'net_revenue' => $summary['net_sales'] ?? null,
                'gross_profit' => $summary['gross_profit'] ?? null,
                'avg_order' => $summary['average_order_value'] ?? null,
                'units' => (int) ($summary['units_sold'] ?? 0),
                'refunds' => (int) ($summary['refunded_orders'] ?? 0),
                'cost_coverage' => $summary['cost_coverage_percent'] ?? null,
            ],
            'products' => $products,
        ];
    }

    /**
     * Dispatch one report to everyone in the tenant who may see profit figures.
     *
     * @return int The number of recipients the dispatcher addressed.
     */
    public function dispatch(Tenant $tenant, string $period, ?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now();
        $eventKey = $this->eventKey($period);
        $data = $this->build($tenant, $period, $now);

        return $this->notifications->dispatch(new NotificationIntent(
            eventKey: $eventKey,
            tenantId: (int) $tenant->getKey(),
            // One report per tenant and period. Re-running the scheduled
            // command on the same day is therefore harmless.
            dedupeKey: $eventKey.':'.$tenant->getKey().':'.md5((string) $data['period']),
            data: $data,
            actionUrl: '/reports',
            severity: NotificationSeverity::Info,
        ));
    }

    private function money(mixed $amount, string $currency): string
    {
        if ($amount === null || $amount === '') {
            return '—';
        }

        $decimals = $this->currencies->supports($currency) ? $this->currencies->decimals($currency) : 2;

        return number_format((float) $amount, $decimals, '.').' '.$currency;
    }
}
