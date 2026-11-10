<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Where a translation's current copy came from.
 *
 * An AI draft and a merchant edit are not the same thing. Keeping the origin
 * separate from the status is what lets regeneration refuse to silently
 * overwrite a human's work.
 */
enum TranslationOrigin: string
{
    case Ai = 'ai';
    case Manual = 'manual';
    case Legacy = 'legacy';
}
