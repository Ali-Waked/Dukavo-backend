<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Whether a newsletter edition is visible on the public site.
 *
 * Deliberately a separate enum from {@see NewsletterStatus}, which tracks a
 * *subscriber's* consent. The two share the word "newsletter" but describe
 * different things, and collapsing them would make a subscriber's state and an
 * edition's state the same column.
 */
enum NewsletterArticleStatus: string
{
    case Draft = 'draft';
    case Published = 'published';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Published => 'Published',
        };
    }
}
