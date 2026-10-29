<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * A provider event Dukavo has already accepted.
 *
 * The ledger exists so duplicate prevention is not inferred from the business
 * rows a webhook happens to write. Meta can redeliver a message or a receipt
 * for any reason; this row is claimed before the event is acted on, and a
 * second claim is a no-op. It also keeps provider noise out of the audit trail:
 * the event is infrastructure, the message and status are business records.
 */
#[Fillable(['tenant_id', 'provider', 'event_key', 'event_type', 'payload_hash', 'processed_at', 'failure'])]
class ChannelWebhookEvent extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return [
            'processed_at' => 'datetime',
        ];
    }

    public function isProcessed(): bool
    {
        return $this->processed_at !== null;
    }
}
