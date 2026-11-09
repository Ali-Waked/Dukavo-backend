<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AutomationTemplateStatus;
use App\Enums\AutomationTrigger;
use App\Models\AutomationTemplate;
use App\Models\AutomationTemplateVersion;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<AutomationTemplate>
 */
class AutomationTemplateFactory extends Factory
{
    protected $model = AutomationTemplate::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->words(3, true);

        return [
            'key' => Str::slug($name).'-'.fake()->unique()->numerify('###'),
            'name' => Str::headline($name),
            'description' => fake()->sentence(),
            'category' => 'orders',
            'trigger' => AutomationTrigger::OrderCreated,
            'conditions' => [],
            'actions' => [
                [
                    'type' => 'create_task',
                    'config' => ['title' => 'Review order {{ order_number }}'],
                ],
            ],
            'configuration_schema' => [],
            'icon' => 'bolt',
            'status' => AutomationTemplateStatus::Active,
            'current_version' => '1.0.0',
            'sort_order' => 0,
            'created_by' => null,
            'updated_by' => null,
        ];
    }

    public function draft(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => AutomationTemplateStatus::Draft,
        ]);
    }

    public function archived(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => AutomationTemplateStatus::Archived,
        ]);
    }

    public function configure(): static
    {
        return $this->afterCreating(function (AutomationTemplate $template): void {
            AutomationTemplateVersion::query()->firstOrCreate(
                [
                    'automation_template_id' => $template->getKey(),
                    'version' => $template->current_version,
                ],
                [
                    'name' => $template->name,
                    'description' => $template->description,
                    'category' => $template->category,
                    'trigger' => $template->trigger->value,
                    'conditions' => $template->conditions ?? [],
                    'actions' => $template->actions ?? [],
                    'configuration_schema' => $template->configuration_schema ?? [],
                    'checksum' => hash('sha256', (string) json_encode($template->definition())),
                    'changelog' => 'Factory snapshot.',
                ],
            );
        });
    }
}
