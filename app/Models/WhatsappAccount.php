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
 * never included in an API resource. The model deliberately knows nothing
 * about the Graph API protocol — connecting, verifying and calling Meta all
 * live behind {@see WhatsAppAccountService} and the gateway it uses.
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
     * Whether a phone number id plus a usable access token are both available.
     *
     * The merchant's own encrypted token always wins. When it is absent, the
     * platform token from `config('services.whatsapp')` may back exactly one
     * number — the one named by `WHATSAPP_PHONE_NUMBER_ID` — which is how a
     * fresh installation exercises the channel before every tenant stores its
     * own credential. It never applies to any other number, so one tenant can
     * never send through another's connection by omitting a token.
     */
    public function hasUsableCredentials(): bool
    {
        if (blank($this->phone_number_id)) {
            return false;
        }

        if (filled($this->access_token)) {
            return true;
        }

        return (string) config('services.whatsapp.phone_number_id') === $this->phone_number_id
            && filled(config('services.whatsapp.cloud_api_token'));
    }

    /**
     * Whether the account has everything the provider needs to send messages.
     */
    public function isUsable(): bool
    {
        return $this->isConnected() && $this->hasUsableCredentials();
    }
}
