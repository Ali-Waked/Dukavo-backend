<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\BillingInterval;
use App\Models\PricingPlan;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<PricingPlan>
 */
class PricingPlanFactory extends Factory
{
    protected $model = PricingPlan::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->words(2, true);

        return [
            'name' => Str::title($name),
            'slug' => Str::slug($name),
            'description' => fake()->sentence(),
            'price' => fake()->randomFloat(2, 0, 99),
            'billing_interval' => BillingInterval::Monthly,
            'currency' => 'USD',
            'features' => [fake()->sentence(3)],
            'limits' => null,
            'highlighted' => false,
            'active' => true,
            'sort_order' => 0,
        ];
    }

    /**
     * A plan whose price has not been published yet.
     */
    public function comingSoon(): static
    {
        return $this->state(fn (array $attributes): array => [
            'price' => null,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes): array => [
            'active' => false,
        ]);
    }
}
