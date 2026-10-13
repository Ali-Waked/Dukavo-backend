<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\WhatsAppAccountStatus;
use App\Models\Concerns\BelongsToTenant;
use App\Services\Channels\WhatsApp\WhatsAppAccountService;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A tenant's WhatsApp Business account.
 *
 * `access_token` is encrypted at rest and hidden from serialisation; it is
 * never included in an API resource. The model deliberately knows nothing about
 * the Meta Graph API — that lives behind
 * {@see WhatsAppAccountService}.
 */
#[Fillable(['tenant_id', 'store_id', 'name', 'phone_number_id', 'business_account_id', 'whatsapp_business_account_id', 'phone_number', 'access_token', 'is_active', 'webhook_verify_token', 'status', 'error_message', 'meta'])]
#[Hidden(['access_token'])]
class WhatsappAccount extends Model
{
    use BelongsToTenant, SoftDeletes;

    protected function casts(): array
    {
        return [
            'status' => WhatsAppAccountStatus::class,
            // Secrets are encrypted at rest; only the platform can decrypt them.
            'access_token' => 'encrypted',
            'meta' => 'array',
            'connected_at' => 'datetime',
            'last_synced_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Store, $this>
     */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function isConnected(): bool
    {
        return $this->status === WhatsAppAccountStatus::Connected;
    }

    /**
     * Whether the account has everything the provider needs to send messages.
     */
    public function isUsable(): bool
    {
        return $this->isConnected()
            && filled($this->phone_number_id)
            && filled($this->access_token);
    }
}
