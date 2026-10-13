<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ContentPage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ContentPage>
 */
class ContentPageFactory extends Factory
{
    protected $model = ContentPage::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'key' => fake()->unique()->slug(2),
            'locale' => 'en',
            'title' => fake()->sentence(3),
            'intro' => fake()->sentence(),
            'sections' => [
                [
                    'id' => 'first',
                    'title' => 'First section',
                    'layout' => 'prose',
                    'body' => fake()->paragraph(),
                ],
            ],
            'active' => true,
            'published_at' => now(),
            'meta_title' => null,
            'meta_description' => null,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes): array => [
            'active' => false,
            'published_at' => null,
        ]);
    }
}
