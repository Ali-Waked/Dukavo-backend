<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\Configuration\PlatformConfigurationService;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One deliberate platform-level override.
 *
 * The model is intentionally dumb: it does not decrypt, cast or expose its
 * value. {@see PlatformConfigurationService} owns
 * encoding, encryption and the write audit, so there is exactly one path a
 * value can take in or out of the table. That is also why the model does not
 * use the {@see Concerns\Auditable} trait — the trait snapshots attributes and
 * would have to be trusted never to include a ciphertext or a secret hint.
 */
#[Fillable(['category', 'key', 'value', 'is_secret', 'secret_hint', 'updated_by'])]
class PlatformSetting extends Model
{
    protected function casts(): array
    {
        return [
            'is_secret' => 'boolean',
            'updated_by' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
