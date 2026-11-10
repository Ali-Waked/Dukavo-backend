<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PaymentMethod;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The customer-facing payment method catalogue.
 *
 * Named `Definition` to keep it distinct from the order-level
 * {@see PaymentMethod} intent. Codes here are specific rails such as
 * `card`, `mada`, `knet`, `benefit` and `apple_pay`.
 */
#[Fillable(['code', 'name', 'name_ar', 'description', 'description_ar', 'kind', 'is_active', 'sort_order'])]
class PaymentMethodDefinition extends Model
{
    protected $table = 'payment_methods';

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return HasMany<PaymentProviderMethod, $this>
     */
    public function providerMethods(): HasMany
    {
        return $this->hasMany(PaymentProviderMethod::class, 'method_code', 'code');
    }

    public function localizedName(?string $locale = null): string
    {
        return str_starts_with(strtolower((string) $locale), 'ar')
            ? $this->name_ar
            : $this->name;
    }

    public function localizedDescription(?string $locale = null): string
    {
        return str_starts_with(strtolower((string) $locale), 'ar')
            ? $this->description_ar
            : $this->description;
    }
}
