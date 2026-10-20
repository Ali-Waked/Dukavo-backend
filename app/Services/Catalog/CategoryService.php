<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Exceptions\BusinessRuleViolation;
use App\Models\Category;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Category tree management.
 *
 * The only non-obvious rule guarded here is that a category may never become
 * its own ancestor, which would create a cycle and break every recursive
 * navigation walk.
 */
class CategoryService
{
    public function __construct(private readonly TenantContext $tenants) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): Category
    {
        $tenant = $this->tenants->required();

        // Honour a slug the merchant typed; derive one from the name only when
        // they left the field blank.
        $slug = $attributes['slug'] ?? null;
        if ($slug === null || $slug === '') {
            $slug = $this->uniqueSlug($attributes['name']);
        }

        try {
            return DB::transaction(fn (): Category => $tenant->categories()->create([
                'parent_id' => $attributes['parent_id'] ?? null,
                'name' => $attributes['name'],
                'slug' => $slug,
                'description' => $attributes['description'] ?? null,
                'is_active' => $attributes['is_active'] ?? true,
                'sort_order' => $attributes['sort_order'] ?? 0,
            ]));
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([
                'slug' => 'This slug is already in use. Please choose another one.',
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(Category $category, array $attributes): Category
    {
        if (array_key_exists('parent_id', $attributes) && $attributes['parent_id'] !== null) {
            $this->assertValidParent($category, (int) $attributes['parent_id']);
        }

        // Clearing the slug keeps the current one; changing the name without a
        // slug regenerates it.
        if (array_key_exists('slug', $attributes) && ($attributes['slug'] === null || $attributes['slug'] === '')) {
            unset($attributes['slug']);
        }

        if (array_key_exists('name', $attributes) && ! array_key_exists('slug', $attributes)) {
            $attributes['slug'] = $this->uniqueSlug($attributes['name'], $category->getKey());
        }

        try {
            $category->fill($attributes)->save();
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([
                'slug' => 'This slug is already in use. Please choose another one.',
            ]);
        }

        return $category;
    }

    public function delete(Category $category): void
    {
        if ($category->children()->exists()) {
            throw BusinessRuleViolation::because('Move or delete the child categories first.');
        }

        if ($category->products()->exists()) {
            throw BusinessRuleViolation::because('This category still has products. Reassign them first.');
        }

        $category->delete();
    }

    /**
     * @return array<int, Category>
     */
    public function tree(bool $onlyActive = true): array
    {
        $categories = Category::query()
            ->when($onlyActive, fn ($query) => $query->active())
            // The tree renders a product count per node, so it is counted here
            // rather than left to each node to query on its own. Soft-deleted
            // products and other tenants' rows are excluded by the relation.
            ->withCount('products')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return $this->buildTree($categories);
    }

    private function assertValidParent(Category $category, int $parentId): void
    {
        if ($parentId === $category->getKey()) {
            throw BusinessRuleViolation::because('A category cannot be its own parent.');
        }

        // Walk upwards: if the requested parent already sits below this
        // category, assigning it would build a loop.
        $cursor = Category::query()->find($parentId);
        $seen = [];

        while ($cursor !== null) {
            if ($cursor->getKey() === $category->getKey()) {
                throw BusinessRuleViolation::because('A category cannot be moved beneath one of its own descendants.');
            }

            if (isset($seen[$cursor->getKey()])) {
                break;
            }

            $seen[$cursor->getKey()] = true;
            $cursor = $cursor->parent_id ? Category::query()->find($cursor->parent_id) : null;
        }
    }

    /**
     * @param  iterable<int, Category>  $categories
     * @return array<int, Category>
     */
    private function buildTree(iterable $categories, ?int $parentId = null): array
    {
        $branches = [];

        foreach ($categories as $category) {
            if ($category->parent_id === $parentId) {
                $category->setRelation('children', collect($this->buildTree($categories, $category->getKey())));
                $branches[] = $category;
            }
        }

        return $branches;
    }

    private function uniqueSlug(string $name, ?int $ignoreId = null): string
    {
        $tenant = $this->tenants->required();

        $base = Str::slug($name) ?: 'category';
        $slug = $base;
        $suffix = 2;

        while (Category::query()
            ->where('tenant_id', $tenant->getKey())
            ->when($ignoreId, fn ($query) => $query->whereKeyNot($ignoreId))
            ->where('slug', $slug)
            ->exists()
        ) {
            $slug = $base.'-'.$suffix++;
        }

        return $slug;
    }
}
