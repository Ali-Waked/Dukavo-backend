<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Who may read a published Help Center article.
 *
 * Visibility is enforced server-side on every read path — the list, the
 * detail, the search results and the feedback route all resolve the article
 * through the same visibility filter, so a restricted article cannot be
 * reached by guessing its slug. The tiers are cumulative from the reader's
 * point of view: a merchant sees public, authenticated and merchant content;
 * a platform operator sees everything.
 */
enum HelpArticleVisibility: string
{
    /** Anyone, including anonymous visitors of the marketing site. */
    case Public = 'public';

    /** Any signed-in, active Dukavo account. */
    case Authenticated = 'authenticated';

    /** Members of at least one merchant workspace. */
    case Merchants = 'merchants';

    /** Dukavo platform operators only. */
    case Platform = 'platform';

    public function label(): string
    {
        return match ($this) {
            self::Public => 'Public',
            self::Authenticated => 'Authenticated',
            self::Merchants => 'Merchants',
            self::Platform => 'Platform Administrators',
        };
    }
}
