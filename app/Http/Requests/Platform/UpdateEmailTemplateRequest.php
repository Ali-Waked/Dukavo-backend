<?php

declare(strict_types=1);

namespace App\Http\Requests\Platform;

use App\Http\Requests\ApiRequest;

class UpdateEmailTemplateRequest extends ApiRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'enabled' => ['sometimes', 'boolean'],
            'subject' => ['sometimes', 'array'],
            'subject.en' => ['sometimes', 'nullable', 'string', 'max:180'],
            'subject.ar' => ['sometimes', 'nullable', 'string', 'max:180'],
            'heading' => ['sometimes', 'array'],
            'heading.en' => ['sometimes', 'nullable', 'string', 'max:180'],
            'heading.ar' => ['sometimes', 'nullable', 'string', 'max:180'],
            'intro' => ['sometimes', 'array'],
            'intro.en' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'intro.ar' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }
}
