<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasTranslations;
use App\Support\LocalizedText;
use Database\Factories\CategoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A self-referencing product grouping. A NULL `parent_id` marks a root
 * category, and the self foreign key cascades deletions as `restrict` to keep
 * accidental subtree deletion impossible by accident.
 */
#[Fillable(['tenant_id', 'parent_id', 'name', 'name_ar', 'slug', 'description', 'description_ar', 'is_active', 'sort_order'])]
class Category extends Model
{
    /** @use HasFactory<CategoryFactory> */
    use Auditable, BelongsToTenant, HasFactory, HasTranslations;

    /**
     * @return list<string>
     */
    protected function auditAttributes(): array
    {
        return ['name', 'name_ar', 'slug', 'description', 'description_ar', 'is_active', 'sort_order'];
    }

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * @return HasMany<Category, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /**
     * @return HasMany<Product, $this>
     */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class, 'category_id');
    }

    #[Scope]
    protected function roots(Builder $query): void
    {
        $query->whereNull('parent_id');
    }

    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * Free-text search across both languages. A merchant searching in Arabic
     * should find a category whose English name they never typed, and vice
     * versa, so every user-visible text column is matched.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function search(Builder $query, ?string $term): void
    {
        $query->when($term, function (Builder $builder, string $value): void {
            $escaped = addcslashes($value, '%_\\');

            $builder->where(function (Builder $inner) use ($escaped): void {
                $inner->where('name', 'like', "%{$escaped}%")
                    ->orWhere('name_ar', 'like', "%{$escaped}%")
                    ->orWhere('slug', 'like', "%{$escaped}%")
                    ->orWhere('description', 'like', "%{$escaped}%")
                    ->orWhere('description_ar', 'like', "%{$escaped}%");
            });
        });
    }

    /** The best available category name for the requested locale. */
    public function localizedName(string $locale): string
    {
        if ($locale !== 'ar') {
            $approved = $this->translatedValue('name', $locale);

            if ($approved !== null) {
                return $approved;
            }
        }

        return (string) LocalizedText::pick($this->name, $this->name_ar, $locale);
    }

    /** The best available description, or null when neither language has one. */
    public function localizedDescription(string $locale): ?string
    {
        if ($locale !== 'ar') {
            $approved = $this->translatedValue('description', $locale);

            if ($approved !== null) {
                return $approved;
            }
        }

        return LocalizedText::pick($this->description, $this->description_ar, $locale);
    }
}
