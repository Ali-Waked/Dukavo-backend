<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\NewsletterStatus;
use App\Models\NewsletterSubscriber;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NewsletterSubscriber>
 */
class NewsletterSubscriberFactory extends Factory
{
    protected $model = NewsletterSubscriber::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'email' => fake()->unique()->safeEmail(),
            'status' => NewsletterStatus::Subscribed,
            'source' => fake()->optional()->randomElement(['website', 'blog', 'referral', 'event']),
            'consented_at' => now(),
            'unsubscribed_at' => null,
        ];
    }

    public function subscribed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => NewsletterStatus::Subscribed,
            'unsubscribed_at' => null,
        ]);
    }

    public function unsubscribed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => NewsletterStatus::Unsubscribed,
            'unsubscribed_at' => now(),
        ]);
    }
}
