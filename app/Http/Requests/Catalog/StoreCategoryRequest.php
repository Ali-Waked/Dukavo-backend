<?php

declare(strict_types=1);

namespace App\Http\Requests\Catalog;

use App\Http\Requests\ApiRequest;
use App\Models\Category;

/**
 * Creating a category.
 *
 * `parent_id` is validated against the catalogue of the active tenant only.
 * Because `Category` is tenant scoped, an id belonging to another tenant does not
 * resolve, so a cross-tenant parent cannot be attached even by guessing ids. A
 * new category cannot be its own parent by construction.
 */
class StoreCategoryRequest extends ApiRequest
{
    protected function prepareForValidation(): void
    {
        $this->prepareSlug();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'slug' => ['nullable', 'string', 'max:120', 'alpha_dash', $this->tenantUnique('categories', 'slug')],
            'parent_id' => ['nullable', 'integer', $this->tenantExists(Category::class)],
            'description' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:100000'],
        ];
    }
}
