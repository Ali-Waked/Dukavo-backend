<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BillingInterval;
use App\Models\Concerns\Auditable;
use Database\Factories\PricingPlanFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A pricing plan shown on the marketing site.
 *
 * Platform-level (no `tenant_id`). `price === null` means "not published yet"
 * and must be rendered as coming soon rather than as zero. The table is
 * configuration the platform edits — it carries no billing behaviour, and
 * every field the marketing site shows is auditable so a price change leaves
 * a trail.
 */
#[Fillable(['name', 'slug', 'description', 'price', 'billing_interval', 'currency', 'features', 'limits', 'highlighted', 'active', 'sort_order'])]
class PricingPlan extends Model
{
    /** @use HasFactory<PricingPlanFactory> */
    use Auditable, HasFactory;

    /**
     * @return list<string>
     */
    protected function auditAttributes(): array
    {
        return ['name', 'price', 'billing_interval', 'currency', 'highlighted', 'active', 'sort_order'];
    }

    protected function casts(): array
    {
        return [
            'billing_interval' => BillingInterval::class,
            'features' => 'array',
            'limits' => 'array',
            'highlighted' => 'boolean',
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
