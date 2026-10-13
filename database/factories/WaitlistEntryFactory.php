<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\WaitlistStatus;
use App\Models\WaitlistEntry;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WaitlistEntry>
 */
class WaitlistEntryFactory extends Factory
{
    protected $model = WaitlistEntry::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'email' => fake()->unique()->safeEmail(),
            'name' => fake()->optional()->name(),
            'business_type' => fake()->optional()->randomElement(['Retail', 'Restaurant', 'Services', 'Wholesale']),
            'source' => fake()->optional()->randomElement(['website', 'referral', 'social']),
            'status' => WaitlistStatus::Pending,
        ];
    }

    public function invited(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => WaitlistStatus::Invited,
        ]);
    }
}
