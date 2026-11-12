<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\HelpSearchLogFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One Help Center search, recorded to power "popular searches" and the
 * zero-result report that tells the platform team what documentation to
 * write next.
 *
 * The term is stored as typed, lower-cased and bounded, with the result count
 * the search produced. Who typed it is recorded only as a nullable user id —
 * guests are anonymous — and the table is pruned on a schedule so the log
 * cannot grow forever.
 */
class HelpSearchLog extends Model
{
    /** @use HasFactory<HelpSearchLogFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = ['term', 'locale', 'results_count', 'user_id'];

    protected function casts(): array
    {
        return [
            'results_count' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Normalise a typed term before it is stored: trimmed, folded to lower
     * case and bounded, so "Products " and "products" aggregate together.
     */
    public static function normaliseTerm(string $term): string
    {
        return mb_substr(mb_strtolower(trim($term)), 0, 200);
    }
}
