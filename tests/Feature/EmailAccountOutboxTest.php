<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\NotificationDeliveryStatus;
use App\Jobs\SendAccountEmail;
use App\Mail\AccountEmail;
use App\Models\NotificationDelivery;
use App\Models\Role;
use App\Models\User;
use App\Services\Emails\AccountEmailService;
use App\Services\Emails\EmailFailureClassifier;
use App\Services\Users\UserService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Account and security mail: transactional, tracked, and never optional.
 *
 * These messages bypass the notification preference switch, but they do not
 * bypass the delivery ledger. The tests prove the outbox row, the queued job,
 * the mockable send, the dedupe key on an invitation, and the Arabic rendering
 * that a merchant receives when their account language is Arabic.
 */
class EmailAccountOutboxTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_a_queued_account_email_is_sent_and_tracked(): void
    {
        $user = User::factory()->create([
            'email' => 'layla@example.test',
            'locale' => 'ar',
        ]);

        Queue::fake();

        $delivery = app(AccountEmailService::class)->send(
            user: $user,
            template: 'verify',
            data: ['minutes' => 60, 'expires' => '2026-11-08 12:00'],
            actionUrl: 'https://app.dukavo.test/verify/example',
        );

        $this->assertSame('verify', $delivery->template);
        $this->assertSame(NotificationDeliveryStatus::Queued, $delivery->status);
        $this->assertSame('user', $delivery->recipient_type);
        $this->assertSame($user->getKey(), $delivery->recipient_id);
        $this->assertSame('l•••@example.test', $delivery->recipient_ref);
        $this->assertSame('https://app.dukavo.test/verify/example', $delivery->payload['action_url']);
        $this->assertSame('layla@example.test', $delivery->payload['data']['email']);

        Queue::assertPushed(SendAccountEmail::class, fn (SendAccountEmail $job): bool => $job->deliveryId === (int) $delivery->getKey());

        Mail::fake();

        (new SendAccountEmail((int) $delivery->getKey()))->handle(app(EmailFailureClassifier::class));

        $delivery->refresh();

        $this->assertSame(NotificationDeliveryStatus::Sent, $delivery->status);
        $this->assertSame(1, $delivery->attempts);
        $this->assertNotNull($delivery->sent_at);

        Mail::assertSent(AccountEmail::class, function (AccountEmail $mail) use ($user): bool {
            return $mail->hasTo($user->email)
                && $mail->locale === 'ar'
                && str_contains($mail->envelope()->subject, 'تحقق من عنوان بريدك الإلكتروني');
        });
    }

    public function test_account_mail_is_sent_even_when_every_optional_preference_is_off(): void
    {
        $user = User::factory()->create();

        $preferences = [];

        foreach (User::NOTIFICATION_CHANNELS as $channel => $events) {
            foreach ($events as $event) {
                $preferences[$channel][$event] = false;
            }
        }

        $user->forceFill(['notification_preferences' => $preferences])->save();

        Queue::fake();

        $delivery = app(AccountEmailService::class)->send(
            user: $user,
            template: 'reset',
            data: ['minutes' => 60],
            actionUrl: 'https://app.dukavo.test/reset-password?token=example',
        );

        $this->assertSame(NotificationDeliveryStatus::Queued, $delivery->status);
        $this->assertNull($delivery->error);
        Queue::assertPushed(SendAccountEmail::class);
    }

    public function test_an_invitation_is_recorded_only_once_for_the_same_dedupe_key(): void
    {
        $user = User::factory()->create(['email' => 'invitee@example.test']);

        Queue::fake();

        $service = app(AccountEmailService::class);

        $first = $service->send(
            user: $user,
            template: 'welcome',
            actionUrl: 'https://app.dukavo.test/login',
            dedupeKey: 'account.welcome:'.$user->getKey(),
        );

        $second = $service->send(
            user: $user,
            template: 'welcome',
            actionUrl: 'https://app.dukavo.test/login',
            dedupeKey: 'account.welcome:'.$user->getKey(),
        );

        $this->assertSame($first->getKey(), $second->getKey());
        $this->assertSame(1, NotificationDelivery::query()->where('dedupe_key', 'account.welcome:'.$user->getKey())->count());
        Queue::assertPushed(SendAccountEmail::class, 1);
    }

    public function test_an_account_email_without_an_address_is_recorded_as_skipped(): void
    {
        $user = User::factory()->create(['email' => '']);

        Queue::fake();

        $delivery = app(AccountEmailService::class)->send(
            user: $user,
            template: 'new_device',
            data: ['device' => 'Chrome on macOS'],
            actionUrl: 'https://app.dukavo.test/settings/security',
        );

        $this->assertSame(NotificationDeliveryStatus::Skipped, $delivery->status);
        $this->assertSame('email_missing', $delivery->error);
        $this->assertNull($delivery->queued_at);
        Queue::assertNotPushed(SendAccountEmail::class);
    }

    public function test_creating_a_tenant_member_queues_a_real_invitation_link_once(): void
    {
        $tenant = $this->tenantWithStore();
        $role = Role::query()->where('slug', 'staff')->firstOrFail();

        Queue::fake();

        $service = app(UserService::class);

        $attributes = [
            'name' => 'New Teammate',
            'email' => 'teammate@example.test',
            'locale' => 'ar',
            'inviter' => 'Owner Name',
        ];

        $user = $service->createForTenant($tenant, $role, $attributes);
        $service->createForTenant($tenant, $role, [
            'name' => 'Second Teammate',
            'email' => 'another@example.test',
            'locale' => 'en',
        ]);

        $delivery = NotificationDelivery::query()
            ->where('recipient_id', $user->getKey())
            ->where('template', 'invitation')
            ->firstOrFail();

        $this->assertSame(NotificationDeliveryStatus::Queued, $delivery->status);
        $this->assertSame('account.invitation:'.$user->getKey().':'.$tenant->getKey(), $delivery->dedupe_key);
        $this->assertStringContainsString('/reset-password?', (string) $delivery->payload['action_url']);
        $this->assertStringContainsString('token=', (string) $delivery->payload['action_url']);
        $this->assertSame($tenant->name, $delivery->payload['data']['tenant']);
        $this->assertSame('ar', $user->locale);

        Queue::assertPushed(SendAccountEmail::class);
    }

    public function test_an_account_job_stops_when_the_recipient_no_longer_exists(): void
    {
        $user = User::factory()->create();

        Queue::fake();

        $delivery = app(AccountEmailService::class)->send(
            user: $user,
            template: 'password_changed',
            actionUrl: 'https://app.dukavo.test/settings/security',
        );

        $user->delete();

        Mail::fake();

        (new SendAccountEmail((int) $delivery->getKey()))->handle(app(EmailFailureClassifier::class));

        $delivery->refresh();

        $this->assertSame(NotificationDeliveryStatus::Skipped, $delivery->status);
        $this->assertSame('email_missing', $delivery->error);
        Mail::assertNothingSent();
    }
}
