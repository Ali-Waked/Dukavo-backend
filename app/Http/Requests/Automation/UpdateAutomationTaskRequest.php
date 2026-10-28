<?php

declare(strict_types=1);

namespace App\Http\Requests\Automation;

use App\Enums\AutomationTaskStatus;
use App\Http\Requests\ApiRequest;
use Illuminate\Validation\Rule;

final class UpdateAutomationTaskRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::enum(AutomationTaskStatus::class)],
        ];
    }
}
