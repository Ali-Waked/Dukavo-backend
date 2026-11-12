<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\HelpArticle;
use App\Models\HelpArticleFeedback;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<HelpArticleFeedback>
 */
class HelpArticleFeedbackFactory extends Factory
{
    protected $model = HelpArticleFeedback::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'article_id' => HelpArticle::factory(),
            'user_id' => User::factory(),
            'ip_hash' => null,
            'helpful' => fake()->boolean(70),
            'comment' => fake()->optional(0.2)->sentence(),
            'created_at' => now()->subDays(fake()->numberBetween(0, 30)),
        ];
    }

    /**
     * An anonymous vote, deduplicated by its salted IP fingerprint.
     */
    public function anonymous(): static
    {
        return $this->state(fn (array $attributes): array => [
            'user_id' => null,
            'ip_hash' => hash('sha256', fake()->unique()->ipv4().config('app.key')),
        ]);
    }
}
