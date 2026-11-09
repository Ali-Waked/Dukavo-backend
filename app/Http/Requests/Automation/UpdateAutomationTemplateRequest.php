<?php

declare(strict_types=1);

namespace App\Http\Requests\Automation;

final class UpdateAutomationTemplateRequest extends AutomationTemplateRequest
{
    protected function updating(): bool
    {
        return true;
    }
}
