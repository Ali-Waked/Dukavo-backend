<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\WaitlistStatus;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\WaitlistEntryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A marketing site waitlist signup.
 *
 * Platform-level: the waitlist belongs to Dukavo itself, so the model is
 * deliberately *not* {@see BelongsToTenant} and must never
 * be given a `tenant_id`. It is intentionally not auditable either — anonymous
 * signups would flood the audit trail, and the row itself is the record.
 */
#[Fillable(['email', 'name', 'business_type', 'source', 'status'])]
class WaitlistEntry extends Model
{
    /** @use HasFactory<WaitlistEntryFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'status' => WaitlistStatus::class,
        ];
    }
}
