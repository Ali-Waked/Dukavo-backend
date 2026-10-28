<?php

declare(strict_types=1);

namespace App\Http\Requests\Automation;

final class UpdateAutomationRuleRequest extends AutomationRuleRequest
{
    protected function updating(): bool
    {
        return true;
    }
}
