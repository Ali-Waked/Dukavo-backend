<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mail\EmailTemplateTestMail;
use App\Mail\NotificationMail;
use App\Models\Notification;
use App\Models\NotificationDelivery;
use App\Models\NotificationEmailTemplate;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Emails\EmailTemplateService;
use App\Services\Notifications\Senders\EmailNotificationSender;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Platform email template administration.
 *
 * Operators may change plain-text copy, preview both directions, disable only
 * optional templates, and restore the shipped defaults. These tests pin the
 * safety rails: no executable HTML, no unknown placeholders, no disabling a
 * security message, and no merchant access to any of it.
 */
class EmailTemplateAdminTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $merchant;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->admin = $this->platformUser('super-admin');
        $this->tenant = $this->tenantWithStore();
        $this->merchant = $this->memberOf($this->tenant);
    }

    public function test_a_merchant_cannot_reach_template_administration(): void
    {
        $this->actingAs($this->merchant, 'sanctum')
            ->getJson('/api/v1/platform/email-templates')
            ->assertForbidden();

        $this->actingAs($this->merchant, 'sanctum')
            ->patchJson('/api/v1/platform/email-templates/account.reset', [
                'subject' => ['en' => 'Hijacked'],
            ])
            ->assertForbidden();
    }

    public function test_the_catalogue_lists_defaults_and_override_state(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/platform/email-templates')
            ->assertOk()
            ->assertJsonPath('success', true);

        $keys = collect($response->json('data.templates'))->pluck('key')->all();

        $this->assertContains('account.reset', $keys);
        $this->assertContains('tenant.order.created', $keys);
        $this->assertContains('platform.broadcast', $keys);

        $account = collect($response->json('data.templates'))->firstWhere('key', 'account.reset');

        $this->assertFalse($account['optional']);
        $this->assertTrue($account['enabled']);
    }

    public function test_copy_is_stored_and_applied_to_the_rendered_email(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->patchJson('/api/v1/platform/email-templates/tenant.order.created', [
                'subject' => ['en' => 'Order {order_number} needs a look', 'ar' => 'الطلب {order_number} يحتاج مراجعة'],
                'heading' => ['en' => 'A new order arrived'],
                'intro' => ['en' => 'Please review the order below.'],
            ])
            ->assertOk()
            ->assertJsonPath('data.effective.subject.en', 'Order {order_number} needs a look');

        $recipient = User::factory()->create(['locale' => 'en']);

        $notification = Notification::factory()->forUser($recipient)->create([
            'event_key' => 'tenant.order.created',
            'title' => 'Stored title',
            'data' => ['order_number' => 'ORD-000777'],
        ]);

        Mail::fake();

        Mail::to($recipient->email)->send(new NotificationMail($notification, $recipient));

        Mail::assertSent(NotificationMail::class, function (NotificationMail $mail): bool {
            return $mail->envelope()->subject === 'Order ORD-000777 needs a look';
        });
    }

    public function test_html_and_unknown_placeholders_are_rejected(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->patchJson('/api/v1/platform/email-templates/tenant.order.created', [
                'intro' => ['en' => 'Hello <script>alert(1)</script>'],
            ])
            ->assertOk()
            ->assertJsonPath('data.effective.intro.en', 'Hello alert(1)');

        $this->actingAs($this->admin, 'sanctum')
            ->patchJson('/api/v1/platform/email-templates/tenant.order.created', [
                'heading' => ['en' => 'Hello {unapproved}'],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['en']);
    }

    public function test_a_required_template_cannot_be_disabled(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->patchJson('/api/v1/platform/email-templates/account.reset', [
                'enabled' => false,
            ])
            ->assertOk()
            ->assertJsonPath('data.enabled', true);
    }

    public function test_disabling_an_optional_template_skips_its_delivery(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->patchJson('/api/v1/platform/email-templates/tenant.report.weekly', [
                'enabled' => false,
            ])
            ->assertOk()
            ->assertJsonPath('data.enabled', false);

        $recipient = User::factory()->create();
        $notification = Notification::factory()->forUser($recipient)->create([
            'event_key' => 'tenant.report.weekly',
        ]);

        $delivery = NotificationDelivery::factory()->create([
            'notification_id' => $notification->getKey(),
            'channel' => 'mail',
            'recipient_type' => 'user',
            'recipient_id' => $recipient->getKey(),
        ]);

        Mail::fake();

        app(EmailNotificationSender::class)->send($delivery);

        $delivery->refresh();

        $this->assertSame('skipped', $delivery->status->value);
        $this->assertSame('template_disabled', $delivery->error);
        Mail::assertNothingSent();
    }

    public function test_restoring_deletes_the_override_and_returns_the_shipped_copy(): void
    {
        $defaults = app(EmailTemplateService::class)->find('tenant.order.created')['defaults'];

        $this->actingAs($this->admin, 'sanctum')
            ->patchJson('/api/v1/platform/email-templates/tenant.order.created', [
                'subject' => ['en' => 'Custom'],
            ])
            ->assertOk();

        $this->assertSame(1, NotificationEmailTemplate::query()->count());

        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/platform/email-templates/tenant.order.created/restore')
            ->assertOk()
            ->assertJsonPath('data.effective.subject.en', $defaults['subject']['en']);

        $this->assertSame(0, NotificationEmailTemplate::query()->count());
    }

    public function test_preview_renders_unsaved_copy_in_the_requested_direction(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/platform/email-templates/tenant.order.created/preview', [
                'locale' => 'ar',
                'heading' => ['ar' => 'عنوان المعاينة'],
            ])
            ->assertOk()
            ->assertJsonPath('data.locale', 'ar')
            ->assertJsonPath('data.content.dir', 'rtl')
            ->assertJsonPath('data.content.heading', 'عنوان المعاينة');

        $this->assertStringContainsString('dir="rtl"', (string) $response->json('data.html'));
    }

    public function test_a_test_send_uses_the_configured_mailer_without_claiming_delivery(): void
    {
        Mail::fake();

        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/platform/email-templates/account.reset/test', [
                'locale' => 'en',
                'email' => 'operator@example.test',
            ])
            ->assertOk()
            ->assertJsonPath('data.delivery_confirmed', false)
            ->assertJsonPath('data.email', 'operator@example.test');

        Mail::assertSent(EmailTemplateTestMail::class, function (EmailTemplateTestMail $mail): bool {
            return $mail->hasTo('operator@example.test')
                && str_starts_with($mail->envelope()->subject, '[Test] ');
        });
    }
}
