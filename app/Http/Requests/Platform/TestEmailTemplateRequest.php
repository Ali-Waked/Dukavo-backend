<?php

declare(strict_types=1);

namespace App\Http\Requests\Platform;

use App\Http\Requests\ApiRequest;

class TestEmailTemplateRequest extends ApiRequest
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
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'locale' => ['sometimes', 'in:en,ar'],
        ];
    }
}
