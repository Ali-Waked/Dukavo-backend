<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mail\AccountEmail;
use App\Mail\NotificationMail;
use App\Models\Inventory;
use App\Models\Order;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Emails\EmailContentBuilder;
use App\Services\Notifications\NotificationEventCatalog as Events;
use App\Services\Orders\OrderService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Lang;
use Tests\TestCase;

/**
 * The branded email presentation layer, rendered rather than assumed.
 *
 * These tests pin the parts a recipient actually sees: the Dukavo logo, the
 * translated heading and labels, the RTL/LTR direction, the real business
 * figures loaded from the tenant's own records, and the plain-text alternative.
 * They render the same Blade views the mailables use, so a component or a
 * translation that stops resolving fails here before it reaches an inbox.
 */
class EmailTemplateRenderingTest extends TestCase
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

    public function test_every_catalogue_event_renders_a_branded_email_in_both_locales(): void
    {
        $builder = app(EmailContentBuilder::class);
        $events = array_keys(app(Events::class)->all());

        $this->assertNotEmpty($events);

        foreach ($events as $eventKey) {
            foreach (['en', 'ar'] as $locale) {
                $content = $builder->buildEvent(
                    eventKey: $eventKey,
                    locale: $locale,
                    data: $this->sampleData(),
                    actionUrl: '/dashboard',
                );

                $html = $this->renderLayout($content, $locale);

                $this->assertNotSame('', $content['heading'], "{$eventKey} has no {$locale} heading.");
                $this->assertStringContainsString('<!DOCTYPE html>', $html);
                $this->assertStringContainsString('https://cdn.dukavo.test/images/email/dukavo-logo.png', $html);
                $this->assertStringContainsString('alt="'.Lang::get('email.brand.name', [], $locale).'"', $html);
                $this->assertStringContainsString('dir="'.($locale === 'ar' ? 'rtl' : 'ltr').'"', $html);
                $this->assertStringContainsString('lang="'.$locale.'"', $html);
                $this->assertStringNotContainsString('<script', strtolower($html));
            }
        }
    }

    public function test_order_email_shows_the_real_order_and_its_lines(): void
    {
        $merchant = $this->memberOf($this->tenant, 'merchant');
        $order = $this->placeOrder(quantity: 2);

        $notification = $merchant->notifications()
            ->where('event_key', Events::TENANT_ORDER_CREATED)
            ->firstOrFail();

        $mailable = new NotificationMail($notification, $merchant);

        $this->assertSame($notification->title, $mailable->envelope()->subject);
        $this->assertSame('en', $mailable->locale);
        $mailable->assertSeeInHtml($order->order_number);
        $mailable->assertSeeInHtml('20.00 USD');
        $mailable->assertSeeInHtml('Order items');
        $mailable->assertSeeInHtml('View order');
        $mailable->assertSeeInHtml('dir="ltr"', false);
        $mailable->assertSeeInHtml('https://cdn.dukavo.test/images/email/dukavo-logo.png', false);
        $mailable->assertSeeInText($order->order_number);
        $mailable->assertSeeInText('Order details');
        $mailable->assertSeeInText('https://app.dukavo.test/orders/'.$order->order_number);
    }

    public function test_order_email_uses_arabic_rtl_for_an_arabic_recipient(): void
    {
        $recipient = $this->memberOf($this->tenant, 'merchant');
        $recipient->forceFill(['locale' => 'ar'])->save();

        $order = $this->placeOrder();

        $notification = $recipient->notifications()
            ->where('event_key', Events::TENANT_ORDER_CREATED)
            ->firstOrFail();

        $mailable = new NotificationMail($notification, $recipient);

        $this->assertSame('ar', $mailable->locale);
        $this->assertStringContainsString('طلب جديد', $mailable->envelope()->subject);
        $this->assertStringContainsString($order->order_number, $mailable->envelope()->subject);
        $mailable->assertSeeInHtml('dir="rtl"', false);
        $mailable->assertSeeInHtml('lang="ar"', false);
        $mailable->assertSeeInHtml('رقم الطلب');
        $mailable->assertSeeInHtml('عرض الطلب');
        $mailable->assertSeeInHtml('دُكافو');
        $mailable->assertSeeInText('تفاصيل الطلب');
        $mailable->assertSeeInText('https://app.dukavo.test/orders/'.$order->order_number);
    }

    public function test_inventory_email_shows_current_stock_from_the_tenant(): void
    {
        ['product' => $product] = $this->stockedProduct($this->tenant, $this->store, 20);

        $inventory = Inventory::query()->withoutGlobalScopes()
            ->where('tenant_id', $this->tenant->getKey())
            ->firstOrFail();

        $inventory->forceFill(['quantity' => 3, 'low_stock_threshold' => 5])->save();

        $content = app(EmailContentBuilder::class)->buildEvent(
            eventKey: Events::TENANT_INVENTORY_LOW_STOCK,
            locale: 'en',
            data: ['inventory_id' => $inventory->getKey()],
            tenantId: (int) $this->tenant->getKey(),
        );

        $html = $this->renderLayout($content, 'en');

        $this->assertStringContainsString($product->name, $html);
        $this->assertStringContainsString('SKU-'.$product->getKey(), $html);
        $this->assertStringContainsString('3', $html);
        $this->assertStringContainsString('5', $html);
        $this->assertStringContainsString('Review inventory', $html);
    }

    public function test_message_email_contains_only_a_safe_preview(): void
    {
        $content = app(EmailContentBuilder::class)->buildEvent(
            eventKey: Events::TENANT_CONVERSATION_MESSAGE_RECEIVED,
            locale: 'en',
            data: [
                'customer' => 'Amina Yusuf',
                'preview' => 'Is the olive oil back in stock?',
                'body' => 'A much longer private conversation body that must not appear in email.',
            ],
            actionUrl: '/conversations',
        );

        $html = $this->renderLayout($content, 'en');

        $this->assertStringContainsString('Amina Yusuf', $html);
        $this->assertStringContainsString('Is the olive oil back in stock?', $html);
        $this->assertStringContainsString('Only a short preview is included', $html);
        $this->assertStringNotContainsString('much longer private conversation', $html);
        $this->assertStringContainsString('https://app.dukavo.test/conversations', $html);
    }

    public function test_report_email_uses_stored_metrics_and_the_reporting_currency(): void
    {
        $content = app(EmailContentBuilder::class)->buildEvent(
            eventKey: Events::TENANT_REPORT_WEEKLY,
            locale: 'en',
            data: [
                'period' => '2026-11-01 – 2026-11-07',
                'currency' => 'EUR',
                'orders' => 42,
                'has_data' => true,
                'summary' => [
                    'orders' => 42,
                    'net_revenue' => 18420.50,
                    'gross_profit' => 6120.25,
                    'avg_order' => 438.58,
                    'units' => 137,
                    'refunds' => 2,
                    'cost_coverage' => 94.2,
                ],
                'products' => [
                    ['name' => 'Olive oil, 500 ml', 'units' => 48, 'net_sales' => 3120.00],
                ],
            ],
        );

        $html = $this->renderLayout($content, 'en');

        $this->assertStringContainsString('18,420.50 EUR', $html);
        $this->assertStringContainsString('6,120.25 EUR', $html);
        $this->assertStringContainsString('438.58 EUR', $html);
        $this->assertStringContainsString('3,120.00 EUR', $html);
        $this->assertStringContainsString('94.2%', $html);
        $this->assertStringContainsString('No estimated rates are used', $html);
        $this->assertStringNotContainsString('USD', $html);
    }

    public function test_account_email_is_branded_and_localised_in_both_directions(): void
    {
        $english = User::factory()->create(['locale' => 'en']);
        $arabic = User::factory()->create(['locale' => 'ar']);

        $englishMail = new AccountEmail(
            recipient: $english,
            template: 'verify',
            data: ['minutes' => 60],
            actionUrl: 'https://app.dukavo.test/verify/example',
        );

        $this->assertSame('Verify your email address', $englishMail->envelope()->subject);
        $englishMail->assertSeeInHtml('dir="ltr"', false);
        $englishMail->assertSeeInHtml('Verify email address');
        $englishMail->assertSeeInHtml('https://app.dukavo.test/verify/example', false);
        $englishMail->assertSeeInHtml('https://cdn.dukavo.test/images/email/dukavo-logo.png', false);
        $englishMail->assertSeeInHtml('cannot be turned off');
        $englishMail->assertSeeInText('https://app.dukavo.test/verify/example');

        $arabicMail = new AccountEmail(
            recipient: $arabic,
            template: 'verify',
            data: ['minutes' => 60],
            actionUrl: 'https://app.dukavo.test/verify/example',
        );

        $this->assertSame('تحقق من عنوان بريدك الإلكتروني', $arabicMail->envelope()->subject);
        $arabicMail->assertSeeInHtml('dir="rtl"', false);
        $arabicMail->assertSeeInHtml('تأكيد البريد الإلكتروني');
        $arabicMail->assertSeeInHtml('دُكافو');
        $arabicMail->assertSeeInText('تنتهي صلاحية رابط التحقق');
    }

    public function test_preference_links_appear_only_where_the_recipient_can_opt_out(): void
    {
        $builder = app(EmailContentBuilder::class);

        $order = $builder->buildEvent(Events::TENANT_ORDER_CREATED, 'en', $this->sampleData(), '/orders/1');
        $product = $builder->buildEvent(Events::TENANT_ANNOUNCEMENT_PRODUCT, 'en', $this->sampleData());
        $maintenance = $builder->buildEvent(Events::TENANT_ANNOUNCEMENT_MAINTENANCE, 'en', $this->sampleData());
        $account = $builder->buildAccount('verify', 'en', ['minutes' => 60], '/verify');

        $this->assertTrue($order['show_preferences']);
        $this->assertStringContainsString('/settings/notifications', $order['preferences_url']);

        $this->assertTrue($product['show_preferences']);
        $this->assertTrue($product['show_unsubscribe']);
        $this->assertStringContainsString('/settings/notifications', $product['unsubscribe_url']);

        $this->assertFalse($maintenance['show_preferences']);
        $this->assertFalse($maintenance['show_unsubscribe']);

        $this->assertFalse($account['show_preferences']);
        $this->assertFalse($account['show_unsubscribe']);
        $this->assertNotNull($account['security_note']);
    }

    public function test_a_record_from_another_tenant_is_never_used_in_an_email(): void
    {
        $otherTenant = $this->tenantWithStore();
        $otherStore = Store::query()->withoutGlobalScopes()
            ->where('tenant_id', $otherTenant->getKey())
            ->firstOrFail();

        $secretOrder = $this->withinTenant($otherTenant, fn (): Order => Order::factory()->create([
            'tenant_id' => $otherTenant->getKey(),
            'store_id' => $otherStore->getKey(),
            'customer_name' => 'Secret Customer',
            'order_number' => 'ORD-SECRET',
        ]));

        $content = app(EmailContentBuilder::class)->buildEvent(
            eventKey: Events::TENANT_ORDER_CREATED,
            locale: 'en',
            data: [
                'order_id' => $secretOrder->getKey(),
                'order_number' => 'ORD-SAFE',
                'customer' => 'Fallback Customer',
                'total' => '10.00 USD',
            ],
            tenantId: (int) $this->tenant->getKey(),
        );

        $html = $this->renderLayout($content, 'en');

        $this->assertStringContainsString('ORD-SAFE', $html);
        $this->assertStringContainsString('Fallback Customer', $html);
        $this->assertStringNotContainsString('ORD-SECRET', $html);
        $this->assertStringNotContainsString('Secret Customer', $html);
    }

    /**
     * Render the same layout a mailable uses, in the requested language.
     *
     * @param  array<string, mixed>  $content
     */
    private function renderLayout(array $content, string $locale): string
    {
        $previous = app()->getLocale();
        app()->setLocale($locale);

        try {
            return view('emails.layout', ['content' => $content])->render();
        } finally {
            app()->setLocale($previous);
        }
    }

    private function placeOrder(int $quantity = 1): Order
    {
        ['variant' => $variant] = $this->stockedProduct($this->tenant, $this->store, 20);

        return $this->withinTenant($this->tenant, fn (): Order => app(OrderService::class)->create([
            'store_id' => $this->store->getKey(),
            'items' => [['product_variant_id' => $variant->getKey(), 'quantity' => $quantity]],
        ]));
    }

    /**
     * A superset of the placeholders used by the event catalogue, so every
     * template can be rendered without touching a database record.
     *
     * @return array<string, mixed>
     */
    private function sampleData(): array
    {
        return [
            'order_id' => null,
            'order_number' => 'ORD-1042',
            'customer' => 'Layla Hassan',
            'total' => '249.00 USD',
            'status' => 'Paid',
            'status_value' => 'paid',
            'from' => 'Pending',
            'to' => 'Paid',
            'product' => 'Olive oil, 500 ml',
            'sku' => 'OIL-500',
            'store' => 'Dukavo Demo Store',
            'quantity' => 4,
            'previous_quantity' => 12,
            'new_quantity' => 6,
            'delta' => -6,
            'reason' => 'Stock take',
            'preview' => 'Is the olive oil back in stock?',
            'summary' => 'Move 6 units of OIL-500 to the Riyadh store.',
            'period' => '2026-11-01 – 2026-11-07',
            'currency' => 'USD',
            'orders' => 42,
            'net_revenue' => '18,420.00',
            'has_data' => true,
            'title' => 'Stock counts are now faster',
            'body' => 'A Dukavo update.',
            'cta_url' => '/whats-new',
        ];
    }
}
