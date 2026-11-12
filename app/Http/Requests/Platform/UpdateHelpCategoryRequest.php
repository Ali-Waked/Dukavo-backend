<?php

declare(strict_types=1);

namespace App\Http\Requests\Platform;

use App\Http\Requests\ApiRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Editing a Help Center category. Every field is `sometimes` so the form can
 * submit the one column that changed. The slug is only re-derived when one is
 * actually sent — the public URL is stable by default and changing it is an
 * explicit act, never a side effect of renaming.
 *
 * Uniqueness ignores the row being edited, so re-saving an untouched slug
 * stays valid.
 */
class UpdateHelpCategoryRequest extends ApiRequest
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
        $category = $this->route('helpCategory');

        return [
            'name' => ['sometimes', 'required', 'string', 'max:120'],
            'name_ar' => ['sometimes', 'nullable', 'string', 'max:120'],
            'slug' => [
                'sometimes',
                'required',
                'string',
                'max:140',
                'alpha_dash',
                Rule::unique('help_categories', 'slug')->ignore($category),
            ],
            'description' => ['sometimes', 'nullable', 'string', 'max:500'],
            'description_ar' => ['sometimes', 'nullable', 'string', 'max:500'],
            'icon' => ['sometimes', 'nullable', 'string', 'max:60'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:65535'],
            'is_active' => ['sometimes', 'boolean'],
            'is_featured' => ['sometimes', 'boolean'],
        ];
    }
}
