<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A saved delivery/billing address for a customer.
 *
 * `country` is a two-letter ISO code so address formatting can be done on the
 * frontend without shipping a country table to the browser.
 */
#[Fillable(['tenant_id', 'customer_id', 'type', 'contact_name', 'line_one', 'line_two', 'city', 'state', 'postal_code', 'country', 'is_default'])]
class CustomerAddress extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'contact_name' => $this->contact_name,
            'line_one' => $this->line_one,
            'line_two' => $this->line_two,
            'city' => $this->city,
            'state' => $this->state,
            'postal_code' => $this->postal_code,
            'country' => $this->country,
            'is_default' => $this->is_default,
        ];
    }
}
