<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\HelpArticleStatus;
use App\Enums\HelpArticleVisibility;
use App\Models\HelpArticle;
use App\Models\HelpCategory;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<HelpArticle>
 */
class HelpArticleFactory extends Factory
{
    protected $model = HelpArticle::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $title = ucfirst(fake()->unique()->words(4, true));

        return [
            'category_id' => HelpCategory::factory(),
            'author_id' => User::factory(),
            'title' => $title,
            'slug' => Str::slug($title).'-'.fake()->unique()->numberBetween(1, 100000),
            'excerpt' => fake()->sentence(),
            'body' => '<p>'.fake()->paragraph().'</p><h2>A section</h2><p>'.fake()->paragraph().'</p>',
            'video_url' => null,
            'status' => HelpArticleStatus::Published,
            'visibility' => HelpArticleVisibility::Public,
            'is_featured' => false,
            'sort_order' => fake()->numberBetween(0, 100),
            'published_at' => now(),
            'view_count' => fake()->numberBetween(0, 500),
            'helpful_count' => 0,
            'unhelpful_count' => 0,
        ];
    }

    public function draft(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => HelpArticleStatus::Draft,
            'published_at' => null,
        ]);
    }

    public function inReview(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => HelpArticleStatus::InReview,
            'published_at' => null,
        ]);
    }

    public function archived(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => HelpArticleStatus::Archived,
            'published_at' => null,
        ]);
    }

    public function visibility(HelpArticleVisibility $visibility): static
    {
        return $this->state(fn (array $attributes): array => [
            'visibility' => $visibility,
        ]);
    }
}
