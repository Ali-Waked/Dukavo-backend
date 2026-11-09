<?php

declare(strict_types=1);

namespace App\Http\Requests\Automation;

final class StoreAutomationTemplateRequest extends AutomationTemplateRequest
{
    protected function updating(): bool
    {
        return false;
    }
}
