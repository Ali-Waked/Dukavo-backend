<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mail\NotificationMail;
use App\Models\Notification;
use App\Models\NotificationConsent;
use App\Models\NotificationUnsubscribeToken;
use App\Models\User;
use App\Services\Notifications\UnsubscribeService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Marketing consent and secure unsubscribe links.
 *
 * A link is the only place an anonymous recipient may change a preference, so
 * the token itself is the authority: hashed at rest, single-purpose, expiring
 * and scoped to one channel. Using it writes the authoritative preference *and*
 * an append-only consent record; it can never touch security alerts.
 */
class NotificationUnsubscribeTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->user = User::factory()->create([
            'email' => 'maryam@example.test',
            'notification_preferences' => [
                'email' => ['product_news' => true],
                'in_app' => ['product_news' => true],
            ],
        ]);
    }

    public function test_a_valid_link_reports_its_scope_without_changing_anything(): void
    {
        $issued = app(UnsubscribeService::class)->issue($this->user);

        $this->getJson('/api/v1/unsubscribe/'.$issued['token'])
            ->assertOk()
            ->assertJsonPath('data.valid', true)
            ->assertJsonPath('data.already_used', false)
            ->assertJsonPath('data.channel', 'email')
            ->assertJsonPath('data.category', 'product_news')
            ->assertJsonPath('data.email', 'm***@example.test');

        $this->assertTrue($this->user->fresh()->notificationPreferences()['email']['product_news']);

        // The plain token is never stored.
        $this->assertDatabaseMissing('notification_unsubscribe_tokens', [
            'token_hash' => $issued['token'],
        ]);
    }

    public function test_using_the_link_opts_out_and_records_consent(): void
    {
        $issued = app(UnsubscribeService::class)->issue($this->user);

        $this->postJson('/api/v1/unsubscribe/'.$issued['token'])
            ->assertOk()
            ->assertJsonPath('data.channel', 'email')
            ->assertJsonPath('data.category', 'product_news');

        $preferences = $this->user->fresh()->notificationPreferences();

        $this->assertFalse($preferences['email']['product_news']);
        // Other channels and mandatory security notices are untouched.
        $this->assertTrue($preferences['in_app']['product_news']);
        $this->assertTrue($preferences['email']['security_alerts']);

        $consent = NotificationConsent::query()->sole();

        $this->assertSame($this->user->getKey(), $consent->user_id);
        $this->assertSame('email', $consent->channel);
        $this->assertSame('product_news', $consent->category);
        $this->assertSame('revoked', $consent->action);
        $this->assertSame('unsubscribe_link', $consent->source);
        $this->assertNotNull($consent->ip_address);

        $this->assertNotNull(
            NotificationUnsubscribeToken::query()->where('user_id', $this->user->getKey())->value('used_at'),
        );
    }

    public function test_a_replayed_one_click_post_is_idempotent(): void
    {
        $issued = app(UnsubscribeService::class)->issue($this->user);

        $this->postJson('/api/v1/unsubscribe/'.$issued['token'])->assertOk();
        $this->postJson('/api/v1/unsubscribe/'.$issued['token'])
            ->assertOk()
            ->assertJsonPath('message', 'You are already unsubscribed from these emails.');

        $this->assertSame(1, NotificationConsent::query()->count());
    }

    public function test_an_expired_or_unknown_link_is_gone(): void
    {
        $issued = app(UnsubscribeService::class)->issue($this->user);

        NotificationUnsubscribeToken::query()->where('user_id', $this->user->getKey())->update([
            'expires_at' => now()->subDay(),
        ]);

        $this->getJson('/api/v1/unsubscribe/'.$issued['token'])
            ->assertOk()
            ->assertJsonPath('data.valid', false)
            ->assertJsonPath('data.expired', true);

        $this->postJson('/api/v1/unsubscribe/'.$issued['token'])->assertStatus(410);

        $this->getJson('/api/v1/unsubscribe/not-a-real-token')->assertStatus(410);
        $this->postJson('/api/v1/unsubscribe/not-a-real-token')->assertStatus(410);

        $this->assertSame(0, NotificationConsent::query()->count());
    }

    public function test_changing_the_switch_in_settings_records_consent_history(): void
    {
        $this->actingAs($this->user, 'sanctum')
            ->putJson('/api/v1/profile/notifications', [
                'email' => ['product_news' => false],
            ])
            ->assertOk()
            ->assertJsonPath('data.email.product_news', false);

        $this->actingAs($this->user, 'sanctum')
            ->putJson('/api/v1/profile/notifications', [
                'email' => ['product_news' => true],
            ])
            ->assertOk()
            ->assertJsonPath('data.email.product_news', true);

        $actions = NotificationConsent::query()->orderBy('id')->pluck('action')->all();

        $this->assertSame(['revoked', 'granted'], $actions);
        $this->assertSame('settings', NotificationConsent::query()->first()->source);
    }

    public function test_marketing_mail_carries_a_link_but_operational_mail_does_not(): void
    {
        $marketing = Notification::factory()->forUser($this->user)->create([
            'event_key' => 'tenant.announcement.product',
            'title' => 'A new feature',
            'body' => 'Something useful shipped.',
        ]);

        $mail = new NotificationMail($marketing, $this->user);

        $content = $mail->content()->with['content'];

        $this->assertTrue($content['show_unsubscribe']);
        $this->assertStringContainsString('/unsubscribe?token=', (string) $content['unsubscribe_url']);

        $operational = Notification::factory()->forUser($this->user)->create([
            'event_key' => 'tenant.order.created',
            'title' => 'New order',
            'data' => ['order_number' => 'ORD-1'],
        ]);

        $operationalMail = new NotificationMail($operational, $this->user);
        $operationalContent = $operationalMail->content()->with['content'];

        $this->assertFalse($operationalContent['show_unsubscribe']);
        $this->assertNull($operationalContent['unsubscribe_url']);
    }
}
