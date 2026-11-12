<?php

declare(strict_types=1);

namespace App\Http\Requests\Platform;

use App\Http\Requests\ApiRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Creating a Help Center category.
 *
 * `slug` is derived from the name when omitted. Only an ASCII name produces an
 * honest automatic slug — an Arabic name is left without one so the `required`
 * rule asks for an explicit identifier instead of persisting a misleading
 * transliteration, exactly like newsletter editions and products.
 */
class StoreHelpCategoryRequest extends ApiRequest
{
    protected function prepareForValidation(): void
    {
        if ($this->filled('slug')) {
            $this->merge(['slug' => Str::slug($this->string('slug')->toString())]);

            return;
        }

        $name = $this->string('name')->toString();

        if ($name !== '' && mb_check_encoding($name, 'ASCII')) {
            $this->merge(['slug' => Str::slug($name)]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'name_ar' => ['nullable', 'string', 'max:120'],
            'slug' => ['required', 'string', 'max:140', 'alpha_dash', 'unique:help_categories,slug'],
            'description' => ['nullable', 'string', 'max:500'],
            'description_ar' => ['nullable', 'string', 'max:500'],
            'icon' => ['nullable', 'string', 'max:60'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:65535'],
            'is_active' => ['sometimes', 'boolean'],
            'is_featured' => ['sometimes', 'boolean'],
        ];
    }
}
