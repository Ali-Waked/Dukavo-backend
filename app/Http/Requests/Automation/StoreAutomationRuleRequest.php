<?php

declare(strict_types=1);

namespace App\Http\Requests\Automation;

final class StoreAutomationRuleRequest extends AutomationRuleRequest
{
    protected function updating(): bool
    {
        return false;
    }
}
