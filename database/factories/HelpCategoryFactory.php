<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\HelpCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<HelpCategory>
 */
class HelpCategoryFactory extends Factory
{
    protected $model = HelpCategory::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = ucfirst(fake()->unique()->words(2, true));

        return [
            'name' => $name,
            'slug' => str_replace(' ', '-', strtolower($name)).'-'.fake()->unique()->numberBetween(1, 100000),
            'description' => fake()->sentence(),
            'icon' => fake()->randomElement(['box', 'settings', 'credit-card', 'bell', 'users', 'chart']),
            'sort_order' => fake()->numberBetween(0, 100),
            'is_active' => true,
            'is_featured' => false,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_active' => false,
        ]);
    }
}
