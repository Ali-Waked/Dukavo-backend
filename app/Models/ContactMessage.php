<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ContactStatus;
use App\Models\Concerns\Auditable;
use Database\Factories\ContactMessageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A message submitted through the public contact form.
 *
 * Platform-level (no `tenant_id`) and auditable with a `status`-only
 * allow-list: the platform team's triage changes are recorded, while the
 * message body itself is never copied into the audit trail.
 */
#[Fillable(['name', 'email', 'subject', 'message', 'status'])]
class ContactMessage extends Model
{
    /** @use HasFactory<ContactMessageFactory> */
    use Auditable, HasFactory;

    /**
     * @return list<string>
     */
    protected function auditAttributes(): array
    {
        return ['status'];
    }

    protected function casts(): array
    {
        return [
            'status' => ContactStatus::class,
        ];
    }
}
