<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\NotificationScope;
use App\Enums\NotificationSeverity;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Notification>
 */
class NotificationFactory extends Factory
{
    protected $model = Notification::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid(),
            // The morph map stores `user`, not the class name, so the factory
            // writes the same value a real notification would.
            'notifiable_type' => 'user',
            'notifiable_id' => User::factory(),
            'tenant_id' => null,
            'scope' => NotificationScope::Platform,
            'event_key' => 'test.notification',
            'category' => 'system',
            'severity' => NotificationSeverity::Info,
            'title' => fake()->sentence(4),
            'body' => fake()->sentence(),
            'action_url' => null,
            'data' => [],
            'dedupe_key' => null,
            'read_at' => null,
            'archived_at' => null,
        ];
    }

    public function read(): static
    {
        return $this->state(fn (): array => ['read_at' => now()]);
    }

    public function unread(): static
    {
        return $this->state(fn (): array => ['read_at' => null]);
    }

    public function forUser(User $user): static
    {
        return $this->state(fn (): array => [
            'notifiable_type' => $user->getMorphClass(),
            'notifiable_id' => $user->getKey(),
        ]);
    }
}
