<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\NewsletterArticleStatus;
use App\Models\Newsletter;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Newsletter>
 */
class NewsletterFactory extends Factory
{
    protected $model = Newsletter::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $title = ucfirst(fake()->unique()->words(3, true));

        return [
            'title' => $title,
            'slug' => Str::slug($title).'-'.fake()->unique()->numberBetween(1, 100000),
            'excerpt' => fake()->sentence(),
            'body' => '<p>'.fake()->paragraph().'</p><h2>A section</h2><p>'.fake()->paragraph().'</p>',
            'cover_image_path' => null,
            'cover_image_disk' => null,
            'status' => NewsletterArticleStatus::Published,
            'published_at' => now(),
            'meta_title' => null,
            'meta_description' => null,
        ];
    }

    public function draft(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => NewsletterArticleStatus::Draft,
            'published_at' => null,
        ]);
    }
}
