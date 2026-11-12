<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Where a Help Center article sits in its editorial lifecycle.
 *
 * Only `published` is ever served by the public or merchant Help Center
 * surfaces. `in_review` is the explicit editorial gate between a draft and
 * something a reader may rely on; `archived` keeps the article and its
 * feedback history while removing it from every reader surface.
 */
enum HelpArticleStatus: string
{
    case Draft = 'draft';
    case InReview = 'in_review';
    case Published = 'published';
    case Archived = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::InReview => 'In Review',
            self::Published => 'Published',
            self::Archived => 'Archived',
        };
    }
}
