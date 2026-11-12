<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\Ai\Support\AiCapability;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * A model a provider offers, and what it can genuinely do.
 *
 * The capabilities here feed the same matching the registry performs on
 * providers, so routing can refuse a task for a model that cannot serve it
 * instead of sending a request that is guaranteed to fail. Prices are
 * informational estimates (USD per million tokens) and never a billing fact.
 */
#[Fillable([
    'provider_id',
    'key',
    'display_name',
    'capabilities',
    'is_active',
    'is_default',
    'input_price',
    'output_price',
    'context_window',
    'notes',
])]
class AiModel extends Model
{
    protected $table = 'ai_models';

    /**
     * @return BelongsTo<AiProvider, $this>
     */
    public function provider(): BelongsTo
    {
        return $this->belongsTo(AiProvider::class);
    }

    /**
     * The declared capabilities.
     *
     * @return list<AiCapability>
     */
    public function capabilityList(): array
    {
        $values = $this->capabilities ?? [];

        if ($values instanceof Collection) {
            $values = $values->all();
        }

        $capabilities = [];

        foreach ((array) $values as $value) {
            $capability = AiCapability::tryFrom((string) $value);

            if ($capability !== null) {
                $capabilities[] = $capability;
            }
        }

        return array_values(array_unique($capabilities, SORT_REGULAR));
    }

    /**
     * Whether this model can serve a request requiring the capabilities.
     *
     * @param  list<AiCapability>  $required
     */
    public function satisfies(array $required): bool
    {
        $declared = $this->capabilityList();

        foreach ($required as $capability) {
            $satisfied = false;

            foreach ($declared as $supported) {
                if ($supported->satisfies($capability)) {
                    $satisfied = true;

                    break;
                }
            }

            if (! $satisfied) {
                return false;
            }
        }

        return true;
    }

    /**
     * The routes that name this model explicitly.
     *
     * Routes reference a model by its string key rather than a foreign
     * key, so the join is on `model` → `key`.
     *
     * @return HasMany<AiTaskRoute, $this>
     */
    public function routes(): HasMany
    {
        return $this->hasMany(AiTaskRoute::class, 'model', 'key');
    }

    protected function casts(): array
    {
        return [
            'capabilities' => 'array',
            'is_active' => 'boolean',
            'is_default' => 'boolean',
            'input_price' => 'decimal:4',
            'output_price' => 'decimal:4',
            'context_window' => 'integer',
        ];
    }
}
