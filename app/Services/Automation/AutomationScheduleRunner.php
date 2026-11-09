<?php

declare(strict_types=1);

namespace App\Services\Automation;

use App\Enums\AutomationRuleStatus;
use App\Enums\AutomationTrigger;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\TenantStatus;
use App\Events\Automation\ScheduledAutomationEvent;
use App\Models\AutomationRule;
use App\Models\Order;
use App\Models\Store;
use App\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Collection;

/**
 * Produces the synthetic events for time-based automation triggers.
 *
 * The command runs from the scheduler, not from a request, and dispatches one
 * event per real store or per unpaid order. Each event key is dated, so running
 * the command again in the same day or week is a no-op for every rule.
 */
final class AutomationScheduleRunner
{
    public function __construct(
        private readonly TenantContext $tenants,
    ) {}

    /**
     * @return array{tenants: int, rules: int, events: int, skipped: int}
     */
    public function run(string $scope = 'daily', ?int $tenantId = null, bool $dryRun = false): array
    {
        $triggers = match ($scope) {
            'daily' => [AutomationTrigger::ScheduleDaily, AutomationTrigger::OrderAbandoned],
            'weekly' => [AutomationTrigger::ScheduleWeekly],
            'all' => [AutomationTrigger::ScheduleDaily, AutomationTrigger::ScheduleWeekly, AutomationTrigger::OrderAbandoned],
            default => throw new \InvalidArgumentException('Unknown automation schedule scope.'),
        };

        $rules = AutomationRule::query()
            ->acrossTenants()
            ->where('status', AutomationRuleStatus::Active)
            ->whereIn('trigger', array_map(static fn (AutomationTrigger $trigger): string => $trigger->value, $triggers))
            ->when($tenantId !== null, fn ($query) => $query->where('tenant_id', $tenantId))
            ->orderBy('tenant_id')
            ->get();

        $stats = ['tenants' => 0, 'rules' => $rules->count(), 'events' => 0, 'skipped' => 0];

        foreach ($rules->groupBy('tenant_id') as $groupId => $tenantRules) {
            $tenant = Tenant::query()
                ->whereKey((int) $groupId)
                ->where('status', TenantStatus::Active->value)
                ->first();

            if (! $tenant instanceof Tenant) {
                $stats['skipped'] += $tenantRules->count();

                continue;
            }

            $stats['tenants']++;
            $events = $this->tenants->run($tenant, function () use ($tenant, $tenantRules, $scope, $dryRun): int {
                $dispatched = 0;

                if (in_array($scope, ['daily', 'all'], true)) {
                    $dispatched += $this->dispatchSchedule($tenant, $tenantRules, AutomationTrigger::ScheduleDaily, $dryRun);
                    $dispatched += $this->dispatchAbandonedOrders($tenant, $tenantRules, $dryRun);
                }

                if (in_array($scope, ['weekly', 'all'], true)) {
                    $dispatched += $this->dispatchSchedule($tenant, $tenantRules, AutomationTrigger::ScheduleWeekly, $dryRun);
                }

                return $dispatched;
            });

            $stats['events'] += $events;
        }

        return $stats;
    }

    /**
     * @param  Collection<int, AutomationRule>  $rules
     */
    private function dispatchSchedule(Tenant $tenant, Collection $rules, AutomationTrigger $trigger, bool $dryRun): int
    {
        $matching = $rules->where('trigger', $trigger);

        if ($matching->isEmpty()) {
            return 0;
        }

        $storeIds = $matching->pluck('store_id')->unique()->values()->all();
        $storeIds = array_values(array_filter($storeIds, static fn (mixed $storeId): bool => $storeId !== null));

        // A tenant-wide rule is covered by the first store event. When no
        // store-scoped rule exists, dispatch once with a null store so the
        // engine's forStore(null) branch can match it.
        if ($storeIds === []) {
            $storeIds = [null];
        }

        $count = 0;

        foreach ($storeIds as $storeId) {
            $store = $storeId !== null ? Store::query()->find($storeId) : null;
            $context = $this->scheduleContext($trigger, $tenant, $store);
            $eventKey = $this->scheduleKey($trigger);

            if (! $dryRun) {
                event(new ScheduledAutomationEvent(
                    tenantId: (int) $tenant->getKey(),
                    storeId: $storeId,
                    trigger: $trigger,
                    eventKey: $eventKey,
                    context: $context,
                ));
            }

            $count++;
        }

        return $count;
    }

    /**
     * @param  Collection<int, AutomationRule>  $rules
     */
    private function dispatchAbandonedOrders(Tenant $tenant, Collection $rules, bool $dryRun): int
    {
        if (! $rules->contains(static fn (AutomationRule $rule): bool => $rule->trigger === AutomationTrigger::OrderAbandoned)) {
            return 0;
        }

        $orders = Order::query()
            ->where('status', '!=', OrderStatus::Cancelled->value)
            ->whereIn('payment_status', [PaymentStatus::Unpaid->value, PaymentStatus::PartiallyPaid->value])
            ->whereNotNull('placed_at')
            ->where('placed_at', '<=', now()->subHour())
            ->orderBy('id')
            ->limit(200)
            ->get();

        $date = now()->toDateString();
        $count = 0;

        foreach ($orders as $order) {
            $placedAt = $order->placed_at;

            if ($placedAt === null) {
                continue;
            }

            $context = [
                'order_id' => (int) $order->getKey(),
                'order_number' => (string) $order->order_number,
                'status' => $order->status->value,
                'payment_status' => $order->payment_status->value,
                'grand_total' => (float) $order->grand_total,
                'currency' => (string) $order->currency,
                'store_id' => $order->store_id,
                'customer_id' => $order->customer_id,
                'customer_name' => (string) $order->customer_name,
                'customer_email' => (string) ($order->customer_email ?? ''),
                'customer_phone' => (string) ($order->customer_phone ?? ''),
                'age_hours' => (int) max(0, $placedAt->diffInHours(now())),
                'placed_at' => $placedAt->toIso8601String(),
            ];

            if (! $dryRun) {
                event(new ScheduledAutomationEvent(
                    tenantId: (int) $tenant->getKey(),
                    storeId: $order->store_id,
                    trigger: AutomationTrigger::OrderAbandoned,
                    eventKey: sprintf('order.abandoned:%d:%s', $order->getKey(), $date),
                    context: $context,
                ));
            }

            $count++;
        }

        return $count;
    }

    /**
     * @return array<string, scalar|null>
     */
    private function scheduleContext(AutomationTrigger $trigger, Tenant $tenant, ?Store $store): array
    {
        $context = AutomationTriggerRegistry::sampleContext($trigger);

        $context['store_id'] = $store?->getKey();
        $context['store_name'] = $store?->name ?? $tenant->name;

        if ($trigger === AutomationTrigger::ScheduleDaily) {
            $context['date'] = now()->toDateString();
            $context['weekday'] = now()->englishDayOfWeek;
        }

        if ($trigger === AutomationTrigger::ScheduleWeekly) {
            $context['week_start'] = now()->startOfWeek()->toDateString();
            $context['week_end'] = now()->endOfWeek()->toDateString();
            $context['iso_week'] = (int) now()->isoWeek();
            $context['year'] = (int) now()->year;
        }

        return $context;
    }

    private function scheduleKey(AutomationTrigger $trigger): string
    {
        return match ($trigger) {
            AutomationTrigger::ScheduleDaily => 'schedule.daily:'.now()->toDateString(),
            AutomationTrigger::ScheduleWeekly => sprintf('schedule.weekly:%d-W%02d', (int) now()->isoWeekYear(), (int) now()->isoWeek()),
            default => $trigger->value,
        };
    }
}
