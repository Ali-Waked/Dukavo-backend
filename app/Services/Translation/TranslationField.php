<?php

declare(strict_types=1);

namespace App\Services\Translation;

/**
 * One field of an entity that may be translated.
 *
 * `format` is what the output validator uses to decide which invariants must
 * survive: plain prose keeps placeholders, numbers, money and URLs; HTML also
 * keeps its tag structure.
 */
final readonly class TranslationField
{
    public function __construct(
        public string $key,
        public string $label,
        public string $format = 'text',
        public int $maxLength = 5000,
    ) {}

    public function isHtml(): bool
    {
        return $this->format === 'html';
    }
}
