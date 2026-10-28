<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\BundleDiscountType;
use App\Enums\BundleStatus;
use App\Models\Bundle;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Bundle>
 */
class BundleFactory extends Factory
{
    protected $model = Bundle::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'store_id' => null,
            'name' => fake()->unique()->words(2, true),
            'description' => fake()->sentence(),
            'status' => BundleStatus::Draft,
            'discount_type' => BundleDiscountType::Percentage,
            'discount_value' => 10,
            'currency' => 'USD',
        ];
    }

    public function active(): static
    {
        return $this->state(fn (array $attributes): array => ['status' => BundleStatus::Active]);
    }

    public function archived(): static
    {
        return $this->state(fn (array $attributes): array => ['status' => BundleStatus::Archived]);
    }
}
