<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AutomationRunStatus;
use App\Enums\AutomationTrigger;
use App\Models\AutomationRule;
use App\Models\AutomationRun;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<AutomationRun>
 */
class AutomationRunFactory extends Factory
{
    protected $model = AutomationRun::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'automation_rule_id' => AutomationRule::factory(),
            'trigger' => AutomationTrigger::OrderCreated,
            'event_key' => (string) Str::uuid(),
            'status' => AutomationRunStatus::Completed,
            'matched' => true,
            'context' => [],
            'actions' => [],
            'error' => null,
            'started_at' => now(),
            'finished_at' => now(),
        ];
    }
}
