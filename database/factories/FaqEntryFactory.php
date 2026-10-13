<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\FaqEntry;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FaqEntry>
 */
class FaqEntryFactory extends Factory
{
    protected $model = FaqEntry::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'question' => fake()->sentence(6).'?',
            'answer' => fake()->paragraph(),
            'category' => fake()->optional()->randomElement(['general', 'pricing', 'channels']),
            'sort_order' => 0,
            'active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes): array => [
            'active' => false,
        ]);
    }
}
