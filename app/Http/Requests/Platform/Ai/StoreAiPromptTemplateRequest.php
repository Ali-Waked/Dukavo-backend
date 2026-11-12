<?php

declare(strict_types=1);

namespace App\Http\Requests\Platform\Ai;

use App\Http\Requests\ApiRequest;
use App\Models\AiTask;
use Illuminate\Validation\Rule;

/**
 * Creating a prompt template for one task and purpose.
 *
 * The key is what a feature resolves its prompt by, so it is unique per
 * task; the task must already be routed.
 */
final class StoreAiPromptTemplateRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'task_key' => ['required', 'string', Rule::exists('ai_tasks', 'key')],
            'key' => [
                'required',
                'string',
                'max:120',
                'alpha_dash',
                Rule::unique('ai_prompt_templates', 'key')->where('task_id', $this->taskId()),
            ],
            'name' => ['required', 'string', 'max:190'],
            'description' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * The task row the template will belong to, resolved from `task_key` so
     * the uniqueness rule can scope the key to that task. Null when the
     * task key itself is invalid, in which case the `exists` rule fails
     * first anyway.
     */
    private function taskId(): ?int
    {
        $taskKey = (string) $this->input('task_key');

        if ($taskKey === '') {
            return null;
        }

        $id = AiTask::query()->where('key', $taskKey)->value('id');

        return is_int($id) ? $id : null;
    }
}
