<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use Database\Factories\HelpArticleFeedbackFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One "was this helpful?" answer about a Help Center article.
 *
 * Privacy-conscious by design: the writer is recorded as a user id when
 * signed in, otherwise as a salted hash of the IP address — never the raw
 * address — so duplicate votes can be refused without keeping identifiable
 * network data. The optional comment is plain text, never rendered as HTML.
 *
 * Rows are immutable: the only mutation anywhere is deletion by a platform
 * operator removing abuse, which also subtracts the vote from the article's
 * denormalised counters.
 */
#[Fillable(['article_id', 'user_id', 'ip_hash', 'helpful', 'comment'])]
class HelpArticleFeedback extends Model
{
    /** @use HasFactory<HelpArticleFeedbackFactory> */
    use Auditable, HasFactory;

    public const UPDATED_AT = null;

    /**
     * @return list<string>
     */
    protected function auditAttributes(): array
    {
        return ['article_id', 'user_id', 'helpful'];
    }

    protected function casts(): array
    {
        return [
            'helpful' => 'boolean',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<HelpArticle, $this>
     */
    public function article(): BelongsTo
    {
        return $this->belongsTo(HelpArticle::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * A one-way salted fingerprint of the caller's address. The app key makes
     * the hash useless outside this deployment, and the raw address never
     * reaches the database or the audit trail.
     */
    public static function hashIp(string $ipAddress): string
    {
        return hash('sha256', $ipAddress.config('app.key'));
    }
}
