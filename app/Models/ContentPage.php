<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use Database\Factories\ContentPageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A long-form public page (terms, privacy, about), addressed by a stable `key`.
 *
 * Platform-level (no `tenant_id`). `sections` is an ordered list of
 * `{ id, title, layout, body }` records: `id` is the heading anchor, `layout`
 * tells the About renderer whether to present a section as prose, steps, cards
 * or a closing call to action, and `body` is plain text — never HTML — which is
 * what keeps published copy safe to render without a sanitiser.
 *
 * Published copy is public content the platform team is accountable for, so the
 * page and its sections are auditable.
 */
#[Fillable(['key', 'locale', 'title', 'intro', 'sections', 'active', 'published_at', 'meta_title', 'meta_description'])]
class ContentPage extends Model
{
    /** @use HasFactory<ContentPageFactory> */
    use Auditable, HasFactory;

    /**
     * @return list<string>
     */
    protected function auditAttributes(): array
    {
        // `sections` is deliberately excluded: it is a JSON array, and the
        // audit trait records scalar snapshots (the same reason a plan's
        // `features` list is left out). The page's identity and publication
        // state are auditable.
        return ['key', 'locale', 'title', 'intro', 'active', 'published_at', 'meta_title', 'meta_description'];
    }

    protected function casts(): array
    {
        return [
            'sections' => 'array',
            'active' => 'boolean',
            'published_at' => 'datetime',
        ];
    }

    /**
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('active', true);
    }

    /** Select only the rows written in one language. */
    #[Scope]
    protected function forLocale(Builder $query, string $locale): void
    {
        $query->where('locale', $locale);
    }
}
