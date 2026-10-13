<?php

declare(strict_types=1);

namespace App\Http\Requests\Catalog;

use App\Http\Requests\ApiRequest;
use App\Models\Category;
use Illuminate\Validation\Validator;

/**
 * Updating a category.
 *
 * A category may not be re-parented beneath one of its own descendants: the
 * service walks the ancestor chain and refuses the move, because a cycle would
 * hang every navigation and every recursive delete.
 */
class UpdateCategoryRequest extends ApiRequest
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
        $category = $this->route('category');
        $ignoreId = $category instanceof Category ? $category->getKey() : null;

        return [
            'name' => ['sometimes', 'string', 'max:120'],
            'slug' => ['sometimes', 'nullable', 'string', 'max:120', 'alpha_dash', $this->tenantUnique('categories', 'slug', $ignoreId)],
            'parent_id' => ['sometimes', 'nullable', 'integer', $this->tenantExists(Category::class)],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:100000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $category = $this->route('category');

            if (! $category instanceof Category || ! $this->filled('parent_id')) {
                return;
            }

            if ((int) $this->input('parent_id') === (int) $category->getKey()) {
                $validator->errors()->add('parent_id', 'A category cannot be its own parent.');
            }
        });
    }
}
