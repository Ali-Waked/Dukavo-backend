<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\ContactMessage;
use App\Models\NewsletterSubscriber;
use App\Models\PricingPlan;
use App\Models\WaitlistEntry;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Platform administration of the marketing surface.
 *
 * These tables belong to Dukavo itself, so the access rules are deliberately
 * blunt: a signed-in platform administrator may edit them, an authenticated
 * tenant member may not, and an anonymous visitor never sees the endpoints
 * exist. The CRUD tests then hold the admin interface to ordinary resource
 * semantics — filters, validation, and 204-on-delete.
 */
class PlatformMarketingAdminTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_marketing_admin_endpoints_require_authentication(): void
    {
        $this->getJson('/api/v1/platform/pricing-plans')
            ->assertStatus(401)
            ->assertJsonPath('success', false);

        $this->postJson('/api/v1/platform/pricing-plans', ['name' => 'X'])
            ->assertStatus(401);

        $this->getJson('/api/v1/platform/newsletter-subscribers')
            ->assertStatus(401);
    }

    public function test_a_tenant_merchant_cannot_reach_the_platform_marketing_endpoints(): void
    {
        $merchant = $this->memberOf($this->tenantWithStore(), 'merchant');

        $this->actingAs($merchant, 'sanctum')
            ->getJson('/api/v1/platform/pricing-plans')
            ->assertStatus(403)
            ->assertJsonPath('success', false);

        $this->actingAs($merchant, 'sanctum')
            ->getJson('/api/v1/platform/waitlist-entries')
            ->assertStatus(403);

        $this->actingAs($merchant, 'sanctum')
            ->getJson('/api/v1/platform/newsletter-subscribers')
            ->assertStatus(403)
            ->assertJsonPath('success', false);
    }

    public function test_a_platform_administrator_can_create_read_update_and_delete_a_pricing_plan(): void
    {
        $admin = $this->platformUser('super-admin');

        $created = $this->actingAs($admin, 'sanctum')->postJson('/api/v1/platform/pricing-plans', [
            'name' => 'Scale',
            'price' => 49.00,
            'billing_interval' => 'monthly',
            'currency' => 'USD',
            'features' => ['Everything in Growth'],
            'highlighted' => false,
            'active' => true,
            'sort_order' => 30,
        ]);

        $created->assertCreated()
            ->assertJsonPath('data.name', 'Scale')
            ->assertJsonPath('data.slug', 'scale')
            ->assertJsonPath('data.price', 49);

        $id = $created->json('data.id');

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/platform/pricing-plans')
            ->assertOk()
            ->assertJsonPath('data.0.slug', 'scale');

        $this->actingAs($admin, 'sanctum')
            ->patchJson('/api/v1/platform/pricing-plans/'.$id, ['price' => null])
            ->assertOk()
            ->assertJsonPath('data.price', null);

        $this->actingAs($admin, 'sanctum')
            ->deleteJson('/api/v1/platform/pricing-plans/'.$id)
            ->assertNoContent();

        $this->assertDatabaseCount('pricing_plans', 0);
    }

    public function test_pricing_plan_validation_rejects_a_duplicate_slug_and_lowercase_currency(): void
    {
        $admin = $this->platformUser('super-admin');
        PricingPlan::factory()->create(['slug' => 'taken']);

        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/platform/pricing-plans', [
            'name' => 'Clash',
            'slug' => 'taken',
            'billing_interval' => 'monthly',
            'currency' => 'USD',
        ])->assertUnprocessable()->assertJsonValidationErrors('slug');

        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/platform/pricing-plans', [
            'name' => 'Lowercase',
            'billing_interval' => 'monthly',
            'currency' => 'usd',
        ])->assertUnprocessable()->assertJsonValidationErrors('currency');

        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/platform/pricing-plans', [
            'name' => 'Interval',
            'billing_interval' => 'forever',
            'currency' => 'USD',
        ])->assertUnprocessable()->assertJsonValidationErrors('billing_interval');
    }

    public function test_a_plan_can_be_published_without_a_price(): void
    {
        $admin = $this->platformUser('super-admin');

        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/platform/pricing-plans', [
            'name' => 'Announced',
            'price' => null,
            'billing_interval' => 'yearly',
            'currency' => 'USD',
        ])->assertCreated()->assertJsonPath('data.price', null);
    }

    public function test_a_platform_administrator_can_manage_faq_entries(): void
    {
        $admin = $this->platformUser('dukavo-admin');

        $created = $this->actingAs($admin, 'sanctum')->postJson('/api/v1/platform/faq-entries', [
            'question' => 'Where is my data stored?',
            'answer' => 'In the workspace you created it in.',
            'category' => 'security',
            'sort_order' => 0,
            'active' => true,
        ]);

        $created->assertCreated()->assertJsonPath('data.question', 'Where is my data stored?');
        $id = $created->json('data.id');

        $this->actingAs($admin, 'sanctum')
            ->patchJson('/api/v1/platform/faq-entries/'.$id, ['active' => false])
            ->assertOk()
            ->assertJsonPath('data.active', false);

        // An inactive entry disappears from the public endpoint immediately.
        $this->getJson('/api/v1/public/faq')->assertOk()->assertJsonPath('data', []);

        $this->actingAs($admin, 'sanctum')
            ->deleteJson('/api/v1/platform/faq-entries/'.$id)
            ->assertNoContent();

        $this->assertDatabaseCount('faq_entries', 0);
    }

    public function test_the_waitlist_can_be_filtered_and_statused_but_never_edited(): void
    {
        $admin = $this->platformUser('super-admin');

        WaitlistEntry::factory()->invited()->create(['email' => 'invited@example.test']);
        WaitlistEntry::factory()->create(['email' => 'pending@example.test']);

        $rows = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/platform/waitlist-entries?status=invited')
            ->assertOk()
            ->json('data');

        $this->assertSame(['invited@example.test'], array_column($rows, 'email'));

        $entry = WaitlistEntry::query()->where('email', 'pending@example.test')->firstOrFail();

        // Only the status moves; the visitor's words stay as written.
        $this->actingAs($admin, 'sanctum')
            ->patchJson('/api/v1/platform/waitlist-entries/'.$entry->getKey(), ['status' => 'invited'])
            ->assertOk()
            ->assertJsonPath('data.status', 'invited');

        $this->actingAs($admin, 'sanctum')
            ->patchJson('/api/v1/platform/waitlist-entries/'.$entry->getKey(), ['status' => 'nonsense'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');

        $this->actingAs($admin, 'sanctum')
            ->deleteJson('/api/v1/platform/waitlist-entries/'.$entry->getKey())
            ->assertNoContent();

        $this->assertDatabaseCount('waitlist_entries', 1);
    }

    public function test_contact_triage_is_audited_without_recording_the_message_body(): void
    {
        $admin = $this->platformUser('super-admin');

        $message = ContactMessage::factory()->create([
            'subject' => 'Refund for order 918',
            'message' => 'My secret order detail lives here.',
            'status' => 'new',
        ]);

        $this->actingAs($admin, 'sanctum')
            ->patchJson('/api/v1/platform/contact-messages/'.$message->getKey(), ['status' => 'read'])
            ->assertOk()
            ->assertJsonPath('data.status', 'read');

        $audit = AuditLog::query()
            ->where('auditable_type', 'contact_message')
            ->where('action', 'updated')
            ->firstOrFail();

        // Only the triage column is allow-listed on the model, so the trail
        // records the workflow step and nothing the visitor wrote.
        $this->assertSame(['status'], array_keys($audit->new_values));
        $this->assertSame('read', $audit->new_values['status']);
        $this->assertSame('new', $audit->old_values['status']);
        $this->assertStringNotContainsString(
            'secret order detail',
            (string) json_encode([$audit->old_values, $audit->new_values]),
        );
    }

    public function test_contact_messages_can_be_searched_by_sender(): void
    {
        $admin = $this->platformUser('super-admin');

        ContactMessage::factory()->create(['email' => 'alice@example.test', 'subject' => 'Pricing']);
        ContactMessage::factory()->create(['email' => 'bob@example.test', 'subject' => 'Integration']);

        $rows = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/platform/contact-messages?search=alice')
            ->assertOk()
            ->json('data');

        $this->assertSame(['alice@example.test'], array_column($rows, 'email'));
    }

    public function test_pricing_updates_are_audited_for_price_changes(): void
    {
        $admin = $this->platformUser('super-admin');
        $plan = PricingPlan::factory()->create(['slug' => 'plan-a', 'price' => 10.00]);

        $this->actingAs($admin, 'sanctum')
            ->patchJson('/api/v1/platform/pricing-plans/'.$plan->getKey(), ['price' => 15.00])
            ->assertOk();

        $audit = AuditLog::query()
            ->where('auditable_type', 'pricing_plan')
            ->where('action', 'updated')
            ->firstOrFail();

        // `old_values` holds only what changed; `new_values` the full
        // allow-listed snapshot — both must show the new price.
        $this->assertSame(10.0, (float) $audit->old_values['price']);
        $this->assertSame(15.0, (float) $audit->new_values['price']);
    }

    public function test_a_platform_administrator_can_manage_newsletter_subscribers(): void
    {
        $admin = $this->platformUser('super-admin');

        NewsletterSubscriber::factory()->create(['email' => 'active@example.test', 'source' => 'blog']);
        NewsletterSubscriber::factory()->unsubscribed()->create(['email' => 'left@example.test']);

        $rows = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/platform/newsletter-subscribers?status=unsubscribed')
            ->assertOk()
            ->json('data');

        $this->assertSame(['left@example.test'], array_column($rows, 'email'));

        $subscriber = NewsletterSubscriber::query()->where('email', 'left@example.test')->firstOrFail();

        // Only the status moves; the email and consent timestamps stay as the
        // subscription flow wrote them.
        $this->actingAs($admin, 'sanctum')
            ->patchJson('/api/v1/platform/newsletter-subscribers/'.$subscriber->getKey(), ['status' => 'subscribed'])
            ->assertOk()
            ->assertJsonPath('data.status', 'subscribed');

        $this->actingAs($admin, 'sanctum')
            ->patchJson('/api/v1/platform/newsletter-subscribers/'.$subscriber->getKey(), ['status' => 'nonsense'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');

        $this->actingAs($admin, 'sanctum')
            ->deleteJson('/api/v1/platform/newsletter-subscribers/'.$subscriber->getKey())
            ->assertNoContent();

        $this->assertDatabaseCount('newsletter_subscribers', 1);
    }

    public function test_the_newsletter_list_can_be_searched_by_address(): void
    {
        $admin = $this->platformUser('dukavo-admin');

        NewsletterSubscriber::factory()->create(['email' => 'alice@example.test', 'source' => 'website']);
        NewsletterSubscriber::factory()->create(['email' => 'bob@example.test', 'source' => 'event']);

        $rows = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/platform/newsletter-subscribers?search=alice')
            ->assertOk()
            ->json('data');

        $this->assertSame(['alice@example.test'], array_column($rows, 'email'));
    }

    public function test_an_unknown_subscriber_id_is_a_404_not_an_exposure(): void
    {
        $admin = $this->platformUser('super-admin');

        // Route model binding resolves `{newsletterSubscriber}` before the
        // controller sees the request, so a missing row is a plain 404.
        $this->actingAs($admin, 'sanctum')
            ->deleteJson('/api/v1/platform/newsletter-subscribers/999999')
            ->assertNotFound()
            ->assertJsonPath('success', false);
    }

    public function test_an_unknown_plan_id_is_a_404_not_an_exposure(): void
    {
        $admin = $this->platformUser('super-admin');

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/platform/pricing-plans/999999')
            ->assertNotFound()
            ->assertJsonPath('success', false);
    }
}
