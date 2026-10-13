<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\NewsletterStatus;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\NewsletterSubscriberFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * An address that consented to Dukavo's newsletter.
 *
 * Platform-level: the list belongs to Dukavo itself, so the model is
 * deliberately *not* {@see BelongsToTenant} and must
 * never be given a `tenant_id`. It is intentionally not auditable either —
 * like the waitlist, the rows are written by anonymous visitors and the row
 * itself is the record of consent.
 */
#[Fillable(['email', 'source', 'status', 'consented_at', 'unsubscribed_at'])]
class NewsletterSubscriber extends Model
{
    /** @use HasFactory<NewsletterSubscriberFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'status' => NewsletterStatus::class,
            'consented_at' => 'datetime',
            'unsubscribed_at' => 'datetime',
        ];
    }
}
