<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\UserStatus;
use App\Http\Resources\ProductImageResource;
use App\Models\Concerns\HasRoles;
use App\Models\Concerns\HasTenantMemberships;
use App\Services\Emails\AccountEmailService;
use App\Services\Rbac\PermissionService;
use App\Support\Tenancy\TenantContext;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Laravel\Sanctum\HasApiTokens;

/**
 * A Dukavo identity.
 *
 * A user is a platform operator (Super Admin / Dukavo Admin), a merchant in one
 * or more tenants, or staff inside a tenant. Roles are therefore attached
 * through `user_roles` with a tenant id, never through a single `role_id`
 * column on this table.
 */
#[Fillable(['name', 'email', 'phone', 'password', 'status', 'locale', 'preferred_currency', 'avatar_path', 'notification_preferences'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, HasTenantMemberships, Notifiable;

    /**
     * The channels and events a user may opt in or out of.
     *
     * `security_alerts` is deliberately absent: account-security notices are
     * transactional and always sent, so offering a switch would be a promise the
     * platform must not keep.
     *
     * @var array<string, list<string>>
     */
    public const NOTIFICATION_CHANNELS = [
        'email' => [
            'order_updates', 'low_stock', 'new_messages', 'conversation_assignments',
            'delivery_failures', 'approvals', 'weekly_summary', 'product_news',
            'team_activity', 'security_alerts',
        ],
        'push' => [
            'order_updates', 'low_stock', 'new_messages', 'conversation_assignments',
            'delivery_failures', 'approvals', 'weekly_summary', 'product_news',
            'team_activity', 'security_alerts',
        ],
        'in_app' => [
            'order_updates', 'low_stock', 'new_messages', 'conversation_assignments',
            'delivery_failures', 'approvals', 'weekly_summary', 'product_news',
            'team_activity', 'security_alerts',
        ],
        'whatsapp' => ['order_updates', 'low_stock', 'new_messages', 'conversation_assignments', 'delivery_failures'],
    ];

    /**
     * Categories a user may never switch off.
     *
     * A security notice is part of the account contract, not marketing: hiding
     * the switch is not enforcement, so the backend forces these on even if a
     * stored payload says otherwise.
     */
    public const MANDATORY_NOTIFICATION_EVENTS = ['security_alerts'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'status' => UserStatus::class,
            'notification_preferences' => 'array',
        ];
    }

    public function isActive(): bool
    {
        return $this->status->canAuthenticate();
    }

    /**
     * The full preference set, with every unset option falling back to its
     * default. Reading through this keeps a partially-stored column from
     * producing an `undefined` in the interface.
     *
     * New operational switches default on only for people whose role actually
     * grants the matching permission, so a store manager is not opted into AI
     * approvals they cannot make and a support agent is not opted into stock
     * alerts they cannot act on. An explicit stored choice always wins.
     *
     * @return array<string, array<string, bool>>
     */
    public function notificationPreferences(?int $tenantId = null): array
    {
        $stored = $this->notification_preferences ?? [];
        $defaults = $this->defaultNotificationPreferences($tenantId);
        $resolved = [];

        foreach (self::NOTIFICATION_CHANNELS as $channel => $events) {
            foreach ($events as $event) {
                $resolved[$channel][$event] = in_array($event, self::MANDATORY_NOTIFICATION_EVENTS, true)
                    ? true
                    : (bool) ($stored[$channel][$event] ?? $defaults[$channel][$event]);
            }
        }

        return $resolved;
    }

    /**
     * Sensible starting point: business-relevant events on, marketing off.
     *
     * Without a tenant there are no permissions to consult, so the role-aware
     * switches start off and only switch on once the active workspace proves
     * the permission.
     *
     * @return array<string, array<string, bool>>
     */
    public function defaultNotificationPreferences(?int $tenantId = null): array
    {
        $defaults = [
            'email' => [
                'order_updates' => true,
                'low_stock' => true,
                'new_messages' => false,
                'conversation_assignments' => false,
                'delivery_failures' => false,
                'approvals' => false,
                'weekly_summary' => true,
                'product_news' => false,
                'team_activity' => false,
                'security_alerts' => true,
            ],
            'push' => [
                'order_updates' => true,
                'low_stock' => true,
                'new_messages' => true,
                'conversation_assignments' => true,
                'delivery_failures' => true,
                'approvals' => true,
                'weekly_summary' => true,
                'product_news' => false,
                'team_activity' => true,
                'security_alerts' => true,
            ],
            'in_app' => [
                'order_updates' => true,
                'low_stock' => true,
                'new_messages' => true,
                'conversation_assignments' => true,
                'delivery_failures' => true,
                'approvals' => true,
                'weekly_summary' => true,
                // Marketing is opt-in on every channel, including in-app.
                // Defaulting it on would put a merchant in an audience they
                // never agreed to just because they opened an account.
                'product_news' => false,
                'team_activity' => true,
                'security_alerts' => true,
            ],
            'whatsapp' => [
                'order_updates' => true,
                'low_stock' => true,
                'new_messages' => true,
                'conversation_assignments' => false,
                'delivery_failures' => false,
            ],
        ];

        $tenantId ??= app(TenantContext::class)->id();

        if ($tenantId === null) {
            return $defaults;
        }

        $tenant = Tenant::query()->find($tenantId);

        if (! $tenant instanceof Tenant) {
            return $defaults;
        }

        $canWorkInbox = $this->hasPermissionTo('conversations.view', $tenant);
        $canApproveAi = $this->hasPermissionTo('ai.approve', $tenant);

        $defaults['email']['conversation_assignments'] = $canWorkInbox;
        $defaults['email']['delivery_failures'] = $canWorkInbox;
        $defaults['whatsapp']['conversation_assignments'] = $canWorkInbox;
        $defaults['whatsapp']['delivery_failures'] = $canWorkInbox;
        $defaults['email']['approvals'] = $canApproveAi;

        return $defaults;
    }

    /**
     * Public URL of the avatar, or null when none is set. Mirrors
     * {@see ProductImageResource}, which is the only other
     * place a stored file is turned into a link.
     */
    public function avatarUrl(): ?string
    {
        if ($this->avatar_path === null || $this->avatar_path === '') {
            return null;
        }

        return Storage::disk('public')->url($this->avatar_path);
    }

    /**
     * Roles assigned with a NULL tenant: platform-wide capabilities.
     *
     * @return BelongsToMany<Role, $this>
     */
    public function platformRoles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'user_roles', 'user_id', 'role_id')
            ->withPivot('tenant_id')
            ->wherePivotNull('tenant_id');
    }

    /**
     * Every role the user holds, platform and tenant scoped alike. Use
     * {@see PermissionService} to resolve the roles that are
     * effective inside a particular tenant — this relation is for display.
     *
     * @return BelongsToMany<Role, $this>
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'user_roles', 'user_id', 'role_id')
            ->withPivot('tenant_id');
    }

    /**
     * @return HasMany<UserRole, $this>
     */
    public function roleAssignments(): HasMany
    {
        return $this->hasMany(UserRole::class);
    }

    /**
     * In-app notifications addressed to this account.
     *
     * Overrides the `Notifiable` trait's own relation so it resolves to
     * Dukavo's enriched notification model rather than Laravel's bare database
     * notification row. A class method takes precedence over the trait's, which
     * is what makes this override safe.
     *
     * @return MorphMany<Notification, $this>
     */
    public function notifications(): MorphMany
    {
        return $this->morphMany(Notification::class, 'notifiable');
    }

    /**
     * Push destinations registered by this account.
     *
     * @return HasMany<NotificationDevice, $this>
     */
    public function notificationDevices(): HasMany
    {
        return $this->hasMany(NotificationDevice::class);
    }

    /**
     * Roles held inside one specific tenant.
     *
     * @return HasMany<UserRole, $this>
     */
    public function tenantRoleAssignments(int $tenantId): HasMany
    {
        return $this->roleAssignments()->where('tenant_id', $tenantId);
    }

    /**
     * Send the verification link through the branded account mailer.
     *
     * Overriding the framework hook (rather than Fortify's default notification)
     * is what gives the message the Dukavo layout, the recipient's own language
     * and a delivery-ledger row. Verification links may be re-requested, so the
     * message is not deduplicated.
     */
    public function sendEmailVerificationNotification(): void
    {
        $minutes = (int) config('auth.verification.expire', 60);
        $url = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes($minutes),
            [
                'id' => $this->getKey(),
                'hash' => sha1($this->getEmailForVerification()),
            ],
        );

        app(AccountEmailService::class)->send(
            user: $this,
            template: 'verify',
            data: ['minutes' => $minutes, 'expires' => now()->addMinutes($minutes)->format('Y-m-d H:i')],
            actionUrl: $url,
        );
    }

    /**
     * Send the password-reset link through the branded account mailer.
     *
     * The token is generated by the password broker, so it is the exact token
     * Fortify's reset endpoint expects. A fresh request invalidates nothing and
     * may be sent again, so no dedupe key is used.
     */
    public function sendPasswordResetNotification(#[\SensitiveParameter] $token): void
    {
        $minutes = (int) config('auth.passwords.users.expire', 60);

        $url = rtrim((string) config('dukavo.urls.frontend'), '/')
            .'/reset-password?'.http_build_query([
                'token' => $token,
                'email' => $this->getEmailForPasswordReset(),
            ]);

        app(AccountEmailService::class)->send(
            user: $this,
            template: 'reset',
            data: ['minutes' => $minutes, 'expires' => now()->addMinutes($minutes)->format('Y-m-d H:i')],
            actionUrl: $url,
        );
    }
}
