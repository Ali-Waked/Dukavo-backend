<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\AuditAction;
use App\Enums\OrderStatus;
use App\Enums\ProductStatus;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Inventory;
use App\Models\Order;
use App\Models\Product;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\Support\DemoPeople;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * The audit trail the demo opens on.
 *
 * Rows are shaped exactly as {@see Auditable} would have written them: a
 * creation stores an empty old snapshot and the full allow-listed one, an
 * update stores only the attributes that changed. Subjects stick to the
 * morph map, so every `auditable_type` resolves the way a real lookup would.
 */
class AuditLogSeeder extends Seeder
{
    /** Stands in for the browser every demo session arrives with. */
    private const USER_AGENT = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36';

    public function run(): void
    {
        $context = app(TenantContext::class);

        foreach (DemoPeople::tenants() as $definition) {
            $tenant = Tenant::query()->where('slug', $definition['slug'])->firstOrFail();

            $context->run($tenant, function () use ($tenant): void {
                $this->seedTenantTrail($tenant);
            });
        }

        $this->seedPlatformTrail();
    }

    private function seedTenantTrail(Tenant $tenant): void
    {
        $members = $tenant->users()->get();

        if ($members->isEmpty()) {
            return;
        }

        $owner = $members->first(fn (User $user): bool => (bool) $user->pivot->is_owner) ?? $members->first();
        $staff = $members->last();

        foreach ($tenant->stores()->orderBy('id')->get() as $store) {
            $this->entry(
                AuditAction::Created,
                $store,
                [],
                $this->snapshot($store, ['name', 'slug', 'status', 'currency', 'timezone', 'is_default']),
                $store->created_at,
                $owner,
            );
        }

        $products = Product::query()->orderBy('id')->get();

        foreach ($products->take(6) as $product) {
            $this->entry(
                AuditAction::Created,
                $product,
                [],
                $this->snapshot($product, ['name', 'slug', 'sku', 'status', 'price', 'cost', 'is_taxable']),
                $product->created_at,
                $owner,
            );
        }

        $active = $products->where('status', ProductStatus::Active)->values();

        foreach ($active->slice(0, 4) as $product) {
            $this->entry(
                AuditAction::Updated,
                $product,
                ['price' => round((float) $product->price * 0.94, 2), 'status' => 'draft'],
                $this->snapshot($product, ['name', 'slug', 'sku', 'status', 'price', 'cost', 'is_taxable']),
                $product->created_at->copy()->addDays(mt_rand(3, 12)),
                $owner,
            );
        }

        foreach (Customer::query()->orderBy('id')->take(4)->get() as $customer) {
            $this->entry(
                AuditAction::Created,
                $customer,
                [],
                $this->snapshot($customer, ['name', 'email', 'phone', 'status', 'locale']),
                $customer->created_at,
                $owner,
            );
        }

        $orders = Order::query()->with('statusHistory')->orderBy('id')->get();

        foreach ($orders->take(4) as $order) {
            // The creation row records what the order looked like when it was
            // placed, not what it has since grown into.
            $this->entry(
                AuditAction::Created,
                $order,
                [],
                [
                    'order_number' => $order->order_number,
                    'status' => OrderStatus::Pending->value,
                    'payment_status' => 'unpaid',
                    'grand_total' => $order->grand_total,
                ],
                $order->placed_at ?? $order->created_at,
                $members[mt_rand(0, $members->count() - 1)],
            );
        }

        foreach ($orders->take(8) as $order) {
            $step = $order->statusHistory->last(
                fn ($history): bool => $history->from_status !== null && $history->to_status === $order->status,
            );

            if ($step === null) {
                continue;
            }

            $this->entry(
                AuditAction::OrderStatusChanged,
                $order,
                ['status' => $step->from_status?->value],
                ['status' => $step->to_status->value],
                $step->created_at ?? $order->placed_at ?? $order->created_at,
                $staff,
            );
        }

        foreach (Inventory::query()->with('product')->orderBy('id')->take(4)->get() as $inventory) {
            if ($inventory->product === null) {
                continue;
            }

            $found = mt_rand(1, 4);

            $this->entry(
                AuditAction::InventoryAdjusted,
                $inventory->product,
                ['quantity' => max(0, (int) $inventory->quantity - $found)],
                ['quantity' => (int) $inventory->quantity],
                $inventory->updated_at,
                $owner,
            );
        }

        // The owner did not start as the owner: a promotion explains the
        // role the account wears today.
        $this->entry(
            AuditAction::RoleChanged,
            $owner,
            ['role' => 'staff'],
            ['role' => 'merchant'],
            $owner->created_at->copy()->addDays(mt_rand(2, 10)),
            User::query()->where('email', 'admin@gmail.com')->first() ?? $owner,
            $tenant->getKey(),
        );
    }

    /**
     * Platform-level events: the admin who opened each shop, and the
     * sign-ins that follow.
     */
    private function seedPlatformTrail(): void
    {
        $platform = User::query()->whereIn('email', ['admin@gmail.com', 'support@dukavo.test'])->get();
        $admin = $platform->firstWhere('email', 'admin@gmail.com');

        foreach (DemoPeople::tenants() as $definition) {
            $tenant = Tenant::query()->where('slug', $definition['slug'])->firstOrFail();

            $this->entry(
                AuditAction::Created,
                $tenant,
                [],
                $this->snapshot($tenant, ['name', 'slug', 'status', 'contact_email', 'currency', 'timezone']),
                $tenant->created_at,
                $admin,
                null,
            );
        }

        foreach ($platform as $user) {
            $this->entry(
                AuditAction::Login,
                null,
                null,
                null,
                now()->subMinutes(mt_rand(30, 60 * 24 * 6)),
                $user,
                null,
            );
        }
    }

    /**
     * @param  list<string>  $attributes
     * @return array<string, mixed>
     */
    private function snapshot(Tenant|Store|Product|Customer $model, array $attributes): array
    {
        $values = [];

        foreach ($attributes as $attribute) {
            $value = $model->getAttribute($attribute);

            $values[$attribute] = match (true) {
                $value instanceof \BackedEnum => $value->value,
                default => $value,
            };
        }

        return $values;
    }

    /**
     * @param  array<string, mixed>|null  $old
     * @param  array<string, mixed>|null  $new
     */
    private function entry(
        AuditAction $action,
        Tenant|Store|Product|Customer|Order|User|null $subject,
        ?array $old,
        ?array $new,
        Carbon|int|string|null $at,
        User $actor,
        ?int $tenantId = null,
    ): void {
        $resolvedTenant = $tenantId;

        if ($resolvedTenant === null && $subject !== null) {
            $subjectTenant = $subject->getAttribute('tenant_id');
            $resolvedTenant = $subjectTenant !== null ? (int) $subjectTenant : null;
        }

        AuditLog::forceCreate([
            'tenant_id' => $resolvedTenant,
            'user_id' => $actor->getKey(),
            'action' => $action,
            'auditable_type' => $subject?->getMorphClass(),
            'auditable_id' => $subject?->getKey(),
            'old_values' => $old,
            'new_values' => $new,
            'ip_address' => '192.0.2.'.mt_rand(2, 250),
            'user_agent' => self::USER_AGENT,
            'created_at' => $at,
        ]);
    }
}
