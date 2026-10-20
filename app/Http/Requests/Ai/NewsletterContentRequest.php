<?php

declare(strict_types=1);

namespace App\Http\Requests\Ai;

use App\Http\Requests\ApiRequest;
use App\Services\Ai\Features\NewsletterAssistant;
use Illuminate\Validation\Rule;

/**
 * A newsletter authoring request.
 *
 * A brief is required to move forward from nothing (outline, draft); an existing
 * body is required to improve one. The size ceilings keep a huge paste from
 * becoming a huge (and expensive) prompt.
 */
class NewsletterContentRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'action' => ['required', Rule::in(NewsletterAssistant::ACTIONS)],
            'brief' => ['required_if:action,outline,draft', 'nullable', 'string', 'max:4000'],
            'audience' => ['nullable', 'string', 'max:200'],
            'body' => ['required_if:action,improve', 'nullable', 'string', 'max:20000'],
        ];
    }
}
