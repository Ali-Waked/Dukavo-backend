<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasTranslations;
use App\Support\LocalizedText;
use Database\Factories\HelpCategoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One Help Center category: a group of articles about one area of Dukavo.
 *
 * Platform-level (no `tenant_id`) — the Help Center documents Dukavo itself.
 * Archiving a category (`is_active = false`) hides it from every reader
 * surface but never touches its articles, which stay published and reachable
 * through search and their own URLs.
 */
#[Fillable([
    'name',
    'name_ar',
    'slug',
    'description',
    'description_ar',
    'icon',
    'sort_order',
    'is_active',
    'is_featured',
])]
class HelpCategory extends Model
{
    /** @use HasFactory<HelpCategoryFactory> */
    use Auditable, HasFactory, HasTranslations;

    /**
     * @return list<string>
     */
    protected function auditAttributes(): array
    {
        return ['name', 'name_ar', 'slug', 'icon', 'sort_order', 'is_active', 'is_featured'];
    }

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'is_active' => 'boolean',
            'is_featured' => 'boolean',
        ];
    }

    /**
     * @return HasMany<HelpArticle, $this>
     */
    public function articles(): HasMany
    {
        return $this->hasMany(HelpArticle::class, 'category_id');
    }

    /** The best available name for the requested locale. */
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

    /** The best available description for the requested locale. */
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

    /**
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * The display order shared by the admin list and every reader surface.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function ordered(Builder $query): void
    {
        $query->orderBy('sort_order')->orderBy('id');
    }
}
