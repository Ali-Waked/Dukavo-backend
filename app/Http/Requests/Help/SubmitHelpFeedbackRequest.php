<?php

declare(strict_types=1);

namespace App\Http\Requests\Help;

use App\Http\Requests\ApiRequest;

/**
 * A "was this helpful?" answer about a Help Center article.
 *
 * The comment is optional plain text, bounded so the platform inbox stays
 * readable; it is never rendered as HTML, so there is nothing to sanitise —
 * the value is stored and shown exactly as typed.
 */
class SubmitHelpFeedbackRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'helpful' => ['required', 'boolean'],
            'comment' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
