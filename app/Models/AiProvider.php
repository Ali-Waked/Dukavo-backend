<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * An AI provider the platform knows about, e.g. Google Gemini or xAI Grok.
 *
 * The row is the provider's identity — its key, its adapter driver and
 * whether it may be used at all. Secrets never live here: they belong to
 * {@see AiProviderCredential}, which carries the ownership (platform vs
 * tenant) that this table deliberately does not.
 */
#[Fillable([
    'key',
    'name',
    'driver',
    'enabled',
    'priority',
    'notes',
    'metadata',
])]
class AiProvider extends Model
{
    use Auditable;

    /**
     * Only identity fields are auditable. Credentials are a separate table
     * and their audit trail records the fact of a change, never the value.
     *
     * @return list<string>
     */
    protected function auditAttributes(): array
    {
        return ['key', 'name', 'driver', 'enabled', 'priority'];
    }

    /**
     * @return HasMany<AiProviderCredential, $this>
     */
    public function credentials(): HasMany
    {
        return $this->hasMany(AiProviderCredential::class, 'provider_id');
    }

    /**
     * The platform-owned credential, when one exists.
     *
     * @return HasOne<AiProviderCredential, $this>
     */
    public function platformCredential(): HasOne
    {
        return $this->hasOne(AiProviderCredential::class, 'provider_id')
            ->whereNull('tenant_id');
    }

    /**
     * @return HasMany<AiModel, $this>
     */
    public function models(): HasMany
    {
        return $this->hasMany(AiModel::class, 'provider_id');
    }

    /**
     * @return HasMany<AiTaskRoute, $this>
     */
    public function routes(): HasMany
    {
        return $this->hasMany(AiTaskRoute::class, 'provider_id');
    }

    /**
     * @return HasOne<AiProviderHealth, $this>
     */
    public function health(): HasOne
    {
        return $this->hasOne(AiProviderHealth::class, 'provider_id');
    }

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'priority' => 'integer',
            'metadata' => 'array',
        ];
    }
}
