<?php

declare(strict_types=1);

namespace App\Services\Help;

use App\Models\HelpArticle;
use App\Models\HelpArticleFeedback;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Recording "was this helpful?" answers.
 *
 * Duplicate prevention is enforced in the database as well as here: one vote
 * per signed-in account per article, and one vote per salted IP fingerprint
 * per article for anonymous visitors. Because both unique indexes exist, a
 * signed-in reader who already voted from another device is still caught by
 * their user id, and an anonymous one by their IP fingerprint. The raw address
 * never reaches storage.
 *
 * The helpful/unhelpful counters on the article row are updated inside the
 * same transaction as the insert, so the denormalised numbers can never drift
 * from the feedback rows they summarise.
 */
class HelpFeedbackService
{
    /**
     * @return array{feedback: HelpArticleFeedback, created: bool}
     */
    public function record(HelpArticle $article, ?User $viewer, ?string $ipHash, bool $helpful, ?string $comment): array
    {
        $existing = $this->existingVote($article, $viewer, $ipHash);

        if ($existing !== null) {
            return ['feedback' => $existing, 'created' => false];
        }

        $feedback = DB::transaction(function () use ($article, $viewer, $ipHash, $helpful, $comment): HelpArticleFeedback {
            /** @var HelpArticleFeedback $feedback */
            $feedback = $article->feedback()->create([
                'user_id' => $viewer?->getKey(),
                'ip_hash' => $viewer === null ? $ipHash : null,
                'helpful' => $helpful,
                'comment' => $comment,
            ]);

            $column = $helpful ? 'helpful_count' : 'unhelpful_count';
            $article->forceFill([$column => $article->{$column} + 1])->save();

            return $feedback;
        });

        return ['feedback' => $feedback, 'created' => true];
    }

    /**
     * Remove an abusive or accidental vote. The article's counters are brought
     * back in line inside the same transaction, and the totals never go below
     * zero.
     */
    public function delete(HelpArticleFeedback $feedback): void
    {
        DB::transaction(function () use ($feedback): void {
            $article = $feedback->article;

            $helpful = $feedback->helpful ? max(0, $article->helpful_count - 1) : $article->helpful_count;
            $unhelpful = $feedback->helpful ? $article->unhelpful_count : max(0, $article->unhelpful_count - 1);

            $article->forceFill([
                'helpful_count' => $helpful,
                'unhelpful_count' => $unhelpful,
            ])->save();

            $feedback->delete();
        });
    }

    private function existingVote(HelpArticle $article, ?User $viewer, ?string $ipHash): ?HelpArticleFeedback
    {
        return $article->feedback()
            ->when($viewer !== null, fn ($query) => $query->where('user_id', $viewer->getKey()),
                fn ($query) => $query->where('ip_hash', $ipHash))
            ->first();
    }
}
