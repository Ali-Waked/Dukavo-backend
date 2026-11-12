<?php

declare(strict_types=1);

namespace App\Http\Requests\Platform;

use App\Http\Requests\ApiRequest;
use Illuminate\Support\Str;

/**
 * Creating a Help Center tag. The slug is derived from the name when omitted
 * and must be ASCII, following the same rule as articles and categories.
 */
class StoreHelpTagRequest extends ApiRequest
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
            'name' => ['required', 'string', 'max:80'],
            'name_ar' => ['nullable', 'string', 'max:80'],
            'slug' => ['required', 'string', 'max:100', 'alpha_dash', 'unique:help_tags,slug'],
        ];
    }
}
