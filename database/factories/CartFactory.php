<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\CartStatus;
use App\Models\Cart;
use App\Models\Conversation;
use App\Models\Store;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Cart>
 */
class CartFactory extends Factory
{
    protected $model = Cart::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'conversation_id' => Conversation::factory(),
            'store_id' => Store::factory(),
            'customer_id' => null,
            'status' => CartStatus::Draft,
            'currency' => 'USD',
            'subtotal' => 0,
            'discount_total' => 0,
            'tax_total' => 0,
            'shipping_total' => 0,
            'grand_total' => 0,
            'tax_rate' => 0,
            'version' => 1,
        ];
    }

    public function pendingConfirmation(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => CartStatus::PendingConfirmation,
            'confirmation_requested_at' => now(),
        ]);
    }
}
