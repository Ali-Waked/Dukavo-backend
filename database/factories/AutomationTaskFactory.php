<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AutomationTaskStatus;
use App\Models\AutomationTask;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AutomationTask>
 */
class AutomationTaskFactory extends Factory
{
    protected $model = AutomationTask::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'automation_rule_id' => null,
            'automation_run_id' => null,
            'store_id' => null,
            'assigned_to' => null,
            'created_by' => null,
            'completed_by' => null,
            'title' => fake()->sentence(4),
            'description' => fake()->optional()->paragraph(),
            'priority' => 'normal',
            'status' => AutomationTaskStatus::Open,
            'entity_type' => null,
            'entity_id' => null,
            'metadata' => null,
            'due_at' => null,
            'completed_at' => null,
        ];
    }
}
