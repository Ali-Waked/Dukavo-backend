<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Automation;

use App\Http\Controllers\Controller;
use App\Services\Ai\Governance\Executors\AiActionExecutorRegistry;
use App\Services\Automation\AutomationDefinition;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * The vocabulary the automation editor builds its controls from.
 */
final class AutomationMetaController extends Controller
{
    public function __construct(private readonly AiActionExecutorRegistry $executors) {}

    public function show(): JsonResponse
    {
        return ApiResponse::success('', [
            'triggers' => AutomationDefinition::triggers(),
            'operators' => AutomationDefinition::operators(),
            'actions' => AutomationDefinition::actions(),
            'task_priorities' => AutomationDefinition::taskPriorities(),
            'ai_action_types' => $this->executors->describe(),
        ]);
    }
}
