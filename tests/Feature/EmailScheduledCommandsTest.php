<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\NotificationChannel;
use App\Models\Inventory;
use App\Models\NotificationDelivery;
use App\Models\Order;
use App\Models\Store;
use App\Models\Tenant;
use App\Services\Notifications\NotificationEventCatalog as Events;
use App\Services\Orders\OrderService;
use App\Services\Reporting\ScheduledReportService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * The operator-facing email commands: preview, scheduled reports, restock
 * reminders and announcements.
 *
 * A command that exits successfully but produced nothing would be a silent
 * failure, so each test checks the notification or file that the command is
 * supposed to create. The report test also proves the figures come from the
 * tenant's stored orders in its own currency, not from a sample payload.
 */
class EmailScheduledCommandsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->tenant = $this->tenantWithStore();
        $this->store = Store::query()->withoutGlobalScopes()
            ->where('tenant_id', $this->tenant->getKey())
            ->firstOrFail();

        config([
            'dukavo.email.logo_url' => 'https://cdn.dukavo.test/images/email/dukavo-logo.png',
            'dukavo.urls.frontend' => 'https://app.dukavo.test',
        ]);
    }

    public function test_preview_command_writes_branded_english_and_arabic_examples(): void
    {
        $directory = storage_path('framework/testing/email-previews');

        File::deleteDirectory($directory);

        $this->artisan('email:preview', [
            'template' => 'tenant.order.paid',
            '--locale' => 'en',
            '--output' => $directory,
        ])->assertSuccessful();

        $this->artisan('email:preview', [
            'template' => 'tenant.order.paid',
            '--locale' => 'ar',
            '--output' => $directory,
        ])->assertSuccessful();

        $english = File::get($directory.'/tenant-order-paid-en.html');
        $arabic = File::get($directory.'/tenant-order-paid-ar.html');
        $text = File::get($directory.'/tenant-order-paid-en.txt');

        $this->assertStringContainsString('Payment confirmed for ORD-1042', $english);
        $this->assertStringContainsString('dir="ltr"', $english);
        $this->assertStringContainsString('https://cdn.dukavo.test/images/email/dukavo-logo.png', $english);

        $this->assertStringContainsString('تم تأكيد الدفع للطلب ORD-1042', $arabic);
        $this->assertStringContainsString('dir="rtl"', $arabic);
        $this->assertStringContainsString('lang="ar"', $arabic);

        $this->assertStringContainsString('ORD-1042', $text);
        $this->assertStringContainsString('https://app.dukavo.test/orders/ORD-1042', $text);

        File::deleteDirectory($directory);
    }

    public function test_report_command_dispatches_a_report_for_the_tenant(): void
    {
        $merchant = $this->memberOf($this->tenant, 'merchant');
        $this->placeOrder();

        Mail::fake();

        $this->artisan('email:report', [
            'period' => 'weekly',
            '--tenant' => (string) $this->tenant->getKey(),
        ])->assertSuccessful();

        $this->assertSame(
            1,
            $merchant->notifications()->where('event_key', Events::TENANT_REPORT_WEEKLY)->count(),
        );
    }

    public function test_report_data_is_built_from_real_orders_in_the_store_currency(): void
    {
        $this->memberOf($this->tenant, 'merchant');
        $order = $this->placeOrder();

        $data = $this->withinTenant(
            $this->tenant,
            fn (): array => app(ScheduledReportService::class)->build($this->tenant, 'weekly'),
        );

        $this->assertSame($this->store->currency, $data['currency']);
        $this->assertSame(1, $data['orders']);
        $this->assertSame(1, $data['summary']['orders']);
        $this->assertTrue($data['has_data']);
        $this->assertStringContainsString($order->currency, (string) $data['net_revenue']);
        $this->assertArrayHasKey('gross_profit', $data['summary']);
        $this->assertArrayHasKey('avg_order', $data['summary']);
        $this->assertArrayHasKey('cost_coverage', $data['summary']);
    }

    public function test_restock_command_reminds_the_tenant_about_low_stock(): void
    {
        $merchant = $this->memberOf($this->tenant, 'merchant');

        $this->stockedProduct($this->tenant, $this->store, 20);

        $inventory = Inventory::query()->withoutGlobalScopes()
            ->where('tenant_id', $this->tenant->getKey())
            ->firstOrFail();

        $inventory->forceFill(['quantity' => 3, 'low_stock_threshold' => 5])->save();

        Mail::fake();

        $this->artisan('email:restock-reminders', [
            '--tenant' => (string) $this->tenant->getKey(),
        ])->assertSuccessful();

        $this->assertSame(
            1,
            $merchant->notifications()->where('event_key', Events::TENANT_INVENTORY_RESTOCK_REMINDER)->count(),
        );
    }

    public function test_product_announcements_are_opt_in_but_maintenance_is_forced(): void
    {
        $merchant = $this->memberOf($this->tenant, 'merchant');

        Mail::fake();

        $this->artisan('email:announce', [
            '--tenant' => (string) $this->tenant->getKey(),
            '--type' => 'product',
            '--title' => 'New report filters',
            '--summary' => 'Filter every report by store.',
        ])->assertSuccessful();

        // Marketing is opt-in on every channel, so a fresh account receives
        // neither an in-app record nor an email until it consents.
        $this->assertSame(
            0,
            $merchant->notifications()->where('event_key', Events::TENANT_ANNOUNCEMENT_PRODUCT)->count(),
        );
        $this->assertSame(
            0,
            NotificationDelivery::query()
                ->where('event_key', Events::TENANT_ANNOUNCEMENT_PRODUCT)
                ->where('channel', NotificationChannel::Mail)
                ->count(),
        );

        $this->artisan('email:announce', [
            '--tenant' => (string) $this->tenant->getKey(),
            '--type' => 'maintenance',
            '--title' => 'Scheduled maintenance',
            '--summary' => 'Dukavo will be briefly unavailable.',
        ])->assertSuccessful();

        $this->assertSame(
            1,
            $merchant->notifications()->where('event_key', Events::TENANT_ANNOUNCEMENT_MAINTENANCE)->count(),
        );
        $this->assertSame(
            1,
            NotificationDelivery::query()
                ->where('event_key', Events::TENANT_ANNOUNCEMENT_MAINTENANCE)
                ->where('channel', NotificationChannel::Mail)
                ->count(),
        );

        $merchant->forceFill([
            'notification_preferences' => ['email' => ['product_news' => true]],
        ])->save();

        $this->artisan('email:announce', [
            '--tenant' => (string) $this->tenant->getKey(),
            '--type' => 'release',
            '--title' => 'Release 1.4',
            '--summary' => 'Faster stock adjustments and clearer reports.',
        ])->assertSuccessful();

        $this->assertSame(
            1,
            $merchant->notifications()->where('event_key', Events::TENANT_ANNOUNCEMENT_RELEASE)->count(),
        );
        $this->assertSame(
            1,
            NotificationDelivery::query()
                ->where('event_key', Events::TENANT_ANNOUNCEMENT_RELEASE)
                ->where('channel', NotificationChannel::Mail)
                ->count(),
        );
    }

    private function placeOrder(): Order
    {
        ['variant' => $variant] = $this->stockedProduct($this->tenant, $this->store, 20);

        return $this->withinTenant($this->tenant, fn (): Order => app(OrderService::class)->create([
            'store_id' => $this->store->getKey(),
            'items' => [['product_variant_id' => $variant->getKey(), 'quantity' => 1]],
        ]));
    }
}
