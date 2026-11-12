<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\HelpSearchLog;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<HelpSearchLog>
 */
class HelpSearchLogFactory extends Factory
{
    protected $model = HelpSearchLog::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'term' => mb_substr(mb_strtolower(trim(fake()->words(2, true))), 0, 200),
            'locale' => fake()->randomElement(['en', 'ar']),
            'results_count' => fake()->numberBetween(0, 20),
            'user_id' => null,
            'created_at' => now()->subDays(fake()->numberBetween(0, 30)),
        ];
    }

    public function withoutResults(): static
    {
        return $this->state(fn (array $attributes): array => [
            'results_count' => 0,
        ]);
    }
}
