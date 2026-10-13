<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use Database\Factories\FaqEntryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One entry of the marketing site's FAQ.
 *
 * Platform-level (no `tenant_id`). The whole question/answer pair is auditable
 * because published copy is public content the platform team is accountable
 * for.
 */
#[Fillable(['question', 'answer', 'category', 'sort_order', 'active'])]
class FaqEntry extends Model
{
    /** @use HasFactory<FaqEntryFactory> */
    use Auditable, HasFactory;

    /**
     * @return list<string>
     */
    protected function auditAttributes(): array
    {
        return ['question', 'answer', 'category', 'active', 'sort_order'];
    }

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'sort_order' => 'integer',
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

    /**
     * Display order shared by the admin list and the public endpoint.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function ordered(Builder $query): void
    {
        $query->orderBy('sort_order')->orderBy('id');
    }
}
