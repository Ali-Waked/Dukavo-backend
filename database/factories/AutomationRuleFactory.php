<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AutomationRuleStatus;
use App\Enums\AutomationTrigger;
use App\Models\AutomationRule;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AutomationRule>
 */
class AutomationRuleFactory extends Factory
{
    protected $model = AutomationRule::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'store_id' => null,
            'name' => fake()->unique()->words(3, true),
            'description' => fake()->sentence(),
            'status' => AutomationRuleStatus::Paused,
            'trigger' => AutomationTrigger::OrderCreated,
            'conditions' => [],
            'actions' => [
                [
                    'type' => 'notify_team',
                    'config' => ['message' => 'A new order needs attention.'],
                ],
            ],
            'cooldown_seconds' => 0,
            'created_by' => null,
            'updated_by' => null,
            'run_count' => 0,
            'last_triggered_at' => null,
        ];
    }

    public function active(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => AutomationRuleStatus::Active,
        ]);
    }
}
