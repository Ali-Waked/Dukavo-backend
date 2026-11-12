<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AiCredentialOwner;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One provider credential, owned by the platform or by one tenant.
 *
 * The API key is stored encrypted (Laravel encrypter cast) and is never
 * loaded into a response: the model exposes only {@see self::maskedKey()},
 * which shows the length and the last characters. Tenant isolation is the
 * resolver's job — every query path must scope `tenant_id` deliberately,
 * which is why this model carries no global scope of its own.
 */
#[Fillable([
    'provider_id',
    'tenant_id',
    'api_key',
    'key_hint',
    'base_url',
    'config',
    'last_checked_at',
    'last_check_status',
    'revoked_at',
])]
class AiProviderCredential extends Model
{
    protected $table = 'ai_provider_credentials';

    /**
     * Who owns this credential.
     */
    public function owner(): AiCredentialOwner
    {
        return $this->tenant_id === null
            ? AiCredentialOwner::Platform
            : AiCredentialOwner::Tenant;
    }

    /**
     * The key as an operator may see it: masked, with the last characters.
     *
     * Never the full value, never a prefix that could be brute-forced.
     */
    public function maskedKey(): string
    {
        $hint = (string) ($this->key_hint ?? '');

        if ($hint === '') {
            return '••••••••';
        }

        return str_repeat('•', 12).$hint;
    }

    /**
     * The credential's decrypted key, for backend use only.
     *
     * Callers must never return this to a client, log it or store it. It
     * exists so the provider factory can build a live adapter.
     */
    public function decryptKey(): ?string
    {
        $value = $this->api_key;

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            $decrypted = decrypt($value);
        } catch (DecryptException) {
            return null;
        }

        return is_string($decrypted) && trim($decrypted) !== '' ? $decrypted : null;
    }

    /**
     * @return BelongsTo<AiProvider, $this>
     */
    public function provider(): BelongsTo
    {
        return $this->belongsTo(AiProvider::class);
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'config' => 'array',
            'last_checked_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }
}
