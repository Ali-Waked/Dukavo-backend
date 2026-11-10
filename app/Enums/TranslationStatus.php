<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The review lifecycle of one translation.
 *
 * AI output is never published by being generated. The status is the single
 * source of truth the merchant screen, the approval rules and the public read
 * path all agree on; adding a state means updating all three deliberately.
 */
enum TranslationStatus: string
{
    case NotTranslated = 'not_translated';
    case Generating = 'generating';
    case Generated = 'generated';
    case NeedsReview = 'needs_review';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Failed = 'failed';
    case Stale = 'stale';

    /**
     * Whether the translation has copy that a merchant could review.
     */
    public function hasCopy(): bool
    {
        return in_array($this, [
            self::Generated,
            self::NeedsReview,
            self::Approved,
            self::Rejected,
            self::Stale,
        ], true);
    }

    /**
     * Whether a merchant still has to make a decision about this row.
     */
    public function awaitingReview(): bool
    {
        return in_array($this, [
            self::Generated,
            self::NeedsReview,
            self::Stale,
        ], true);
    }

    /**
     * Whether the public storefront may serve this copy.
     *
     * A stale row keeps the last approved copy visible: the source changed,
     * but hiding a previously approved translation would blank a page for
     * shoppers until someone reviews it. The merchant screen flags it.
     */
    public function isPublic(): bool
    {
        return in_array($this, [self::Approved, self::Stale], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::NotTranslated => 'Not translated',
            self::Generating => 'Generating',
            self::Generated => 'Generated',
            self::NeedsReview => 'Needs review',
            self::Approved => 'Approved',
            self::Rejected => 'Rejected',
            self::Failed => 'Failed',
            self::Stale => 'Stale',
        };
    }
}
