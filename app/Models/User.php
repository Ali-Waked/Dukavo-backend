<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\UserStatus;
use App\Http\Resources\ProductImageResource;
use App\Models\Concerns\HasRoles;
use App\Models\Concerns\HasTenantMemberships;
use App\Services\Rbac\PermissionService;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\HasApiTokens;

/**
 * A Dukavo identity.
 *
 * A user is a platform operator (Super Admin / Dukavo Admin), a merchant in one
 * or more tenants, or staff inside a tenant. Roles are therefore attached
 * through `user_roles` with a tenant id, never through a single `role_id`
 * column on this table.
 */
#[Fillable(['name', 'email', 'phone', 'password', 'status', 'locale', 'avatar_path', 'notification_preferences'])]
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
        'email' => ['order_updates', 'low_stock', 'new_messages', 'weekly_summary', 'product_news'],
        'whatsapp' => ['order_updates', 'low_stock', 'new_messages'],
    ];

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
     * @return array<string, array<string, bool>>
     */
    public function notificationPreferences(): array
    {
        $stored = $this->notification_preferences ?? [];
        $defaults = self::defaultNotificationPreferences();
        $resolved = [];

        foreach (self::NOTIFICATION_CHANNELS as $channel => $events) {
            foreach ($events as $event) {
                $resolved[$channel][$event] = (bool) ($stored[$channel][$event] ?? $defaults[$channel][$event]);
            }
        }

        return $resolved;
    }

    /**
     * Sensible starting point: business-relevant events on, marketing off.
     *
     * @return array<string, array<string, bool>>
     */
    public static function defaultNotificationPreferences(): array
    {
        return [
            'email' => [
                'order_updates' => true,
                'low_stock' => true,
                'new_messages' => false,
                'weekly_summary' => true,
                'product_news' => false,
            ],
            'whatsapp' => [
                'order_updates' => true,
                'low_stock' => true,
                'new_messages' => true,
            ],
        ];
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
     * Roles held inside one specific tenant.
     *
     * @return HasMany<UserRole, $this>
     */
    public function tenantRoleAssignments(int $tenantId): HasMany
    {
        return $this->roleAssignments()->where('tenant_id', $tenantId);
    }
}
