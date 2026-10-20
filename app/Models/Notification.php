<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\NotificationScope;
use App\Enums\NotificationSeverity;
use Database\Factories\NotificationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Str;

/**
 * An in-app notification addressed to a single recipient.
 *
 * The recipient is a `notifiable` morph: a Dukavo user for anything shown in
 * the bell and the centre, or a customer for an outbound-only order message
 * that never surfaces in an interface. Read state belongs to *this* row and is
 * never derived from a delivery: reading a notification does not imply an
 * email was delivered, and an email failure does not touch this record.
 */
#[Fillable([
    'id', 'notifiable_type', 'notifiable_id', 'tenant_id', 'scope', 'event_key',
    'category', 'severity', 'title', 'body', 'action_url', 'data', 'dedupe_key',
    'read_at', 'archived_at',
])]
class Notification extends Model
{
    /** @use HasFactory<NotificationFactory> */
    use HasFactory;

    /**
     * The primary key is a UUID string, assigned here rather than by the
     * database so a notification's identifier never leaks a row count or the
     * order in which alerts arrived.
     */
    protected $keyType = 'string';

    public $incrementing = false;

    protected function casts(): array
    {
        return [
            'scope' => NotificationScope::class,
            'severity' => NotificationSeverity::class,
            'data' => 'array',
            'read_at' => 'datetime',
            'archived_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Notification $notification): void {
            if (blank($notification->getKey())) {
                $notification->setAttribute($notification->getKeyName(), (string) Str::uuid());
            }
        });
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function notifiable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * @return HasMany<NotificationDelivery, $this>
     */
    public function deliveries(): HasMany
    {
        return $this->hasMany(NotificationDelivery::class);
    }

    public function isRead(): bool
    {
        return $this->read_at !== null;
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    public function markAsRead(): bool
    {
        if ($this->isRead()) {
            return false;
        }

        $this->forceFill(['read_at' => now()])->save();

        return true;
    }

    public function archive(): void
    {
        if ($this->isArchived()) {
            return;
        }

        $this->forceFill(['archived_at' => now()])->save();
    }

    /**
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function unread(Builder $query): void
    {
        $query->whereNull('read_at');
    }

    /**
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->whereNull('archived_at');
    }
}
