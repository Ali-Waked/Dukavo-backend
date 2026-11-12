<?php

declare(strict_types=1);

namespace App\Http\Requests\Platform;

use App\Http\Requests\ApiRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Editing a Help Center tag. Uniqueness ignores the row being edited.
 */
class UpdateHelpTagRequest extends ApiRequest
{
    protected function prepareForValidation(): void
    {
        if ($this->filled('slug')) {
            $this->merge(['slug' => Str::slug($this->string('slug')->toString())]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $tag = $this->route('helpTag');

        return [
            'name' => ['sometimes', 'required', 'string', 'max:80'],
            'name_ar' => ['sometimes', 'nullable', 'string', 'max:80'],
            'slug' => [
                'sometimes',
                'required',
                'string',
                'max:100',
                'alpha_dash',
                Rule::unique('help_tags', 'slug')->ignore($tag),
            ],
        ];
    }
}
