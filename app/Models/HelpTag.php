<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasTranslations;
use App\Support\LocalizedText;
use Database\Factories\HelpTagFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * One Help Center tag: a free-form cross-link between articles.
 *
 * A category places an article in one place; tags gather articles across
 * categories ("whatsapp", "getting-started"). Platform-level, like everything
 * else in the Help Center.
 */
#[Fillable(['name', 'name_ar', 'slug'])]
class HelpTag extends Model
{
    /** @use HasFactory<HelpTagFactory> */
    use Auditable, HasFactory, HasTranslations;

    /**
     * @return list<string>
     */
    protected function auditAttributes(): array
    {
        return ['name', 'name_ar', 'slug'];
    }

    /**
     * @return BelongsToMany<HelpArticle, $this>
     */
    public function articles(): BelongsToMany
    {
        return $this->belongsToMany(HelpArticle::class, 'help_article_tag', 'tag_id', 'article_id');
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
}
