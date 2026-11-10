<?php

declare(strict_types=1);

namespace App\Services\Translation;

/**
 * Server-side proof that a translation preserved the parts that are not prose.
 *
 * A model that translates a product name beautifully but drops a `{{code}}`,
 * rewrites a price or breaks an HTML tag has produced unusable content. This
 * validator turns those invariants into hard errors before anything reaches
 * the review table, so a merchant never has to spot a corrupted placeholder.
 */
final class TranslationOutputValidator
{
    /**
     * Tokens that must survive verbatim. Each pattern deliberately excludes
     * ordinary punctuation and URL schemes so false positives stay rare.
     *
     * @var list<string>
     */
    private const PLACEHOLDER_PATTERNS = [
        '/\{\{\s*[\w.]+\s*\}\}/u',
        '/(?<![\w])%[sd]/u',
        '/(?<![\w\/:]):[A-Za-z_][A-Za-z0-9_]*/u',
        '/(?<![\w{])\{[A-Za-z_][A-Za-z0-9_]*\}(?![\w}])/u',
        '/\[\[[A-Za-z0-9_.-]+\]\]/u',
    ];

    /**
     * @return list<string> Human-readable problems; an empty list means valid.
     */
    public function validate(string $source, string $translated, string $format = 'text', array $protectedTerms = []): array
    {
        $errors = [];
        $translated = trim($translated);

        if ($translated === '') {
            return ['The translated text is empty.'];
        }

        $this->compareTokens(
            $this->placeholders($source),
            $this->placeholders($translated),
            'placeholder',
            $errors,
        );

        $this->compareTokens($this->urls($source), $this->urls($translated), 'URL', $errors);
        $this->compareTokens($this->emails($source), $this->emails($translated), 'email address', $errors);

        $sourceDigits = $this->digits($source);
        $translatedDigits = array_flip($this->digits($translated));

        foreach (array_unique($sourceDigits) as $digits) {
            if (! isset($translatedDigits[$digits])) {
                $errors[] = "The number \"{$digits}\" is missing from the translation.";
            }
        }

        $this->compareTokens($this->currencyTokens($source), $this->currencyTokens($translated), 'currency', $errors);
        $this->compareTokens($this->skuTokens($source), $this->skuTokens($translated), 'SKU/reference', $errors);

        foreach ($protectedTerms as $term) {
            $term = trim($term);

            if ($term !== '' && ! str_contains(mb_strtolower($translated), mb_strtolower($term))) {
                $errors[] = "The protected term \"{$term}\" was not preserved.";
            }
        }

        if ($format === 'html') {
            $this->validateHtml($source, $translated, $errors);
        }

        return $errors;
    }

    /**
     * @param  array<string, string>  $sources
     * @param  array<string, string>  $translations
     * @param  array<string, string>  $formats
     * @param  list<string>  $protectedTerms
     * @return array<string, list<string>> Field => problems.
     */
    public function validateMany(array $sources, array $translations, array $formats, array $protectedTerms = []): array
    {
        $errors = [];

        foreach ($sources as $field => $source) {
            $translated = $translations[$field] ?? null;

            if (! is_string($translated)) {
                $errors[$field] = ['The field is missing from the model answer.'];

                continue;
            }

            $fieldErrors = $this->validate($source, $translated, $formats[$field] ?? 'text', $protectedTerms);

            if ($fieldErrors !== []) {
                $errors[$field] = $fieldErrors;
            }
        }

        return $errors;
    }

    /**
     * @return list<string>
     */
    private function placeholders(string $value): array
    {
        $tokens = [];

        foreach (self::PLACEHOLDER_PATTERNS as $pattern) {
            if (preg_match_all($pattern, $value, $matches)) {
                $tokens = array_merge($tokens, $matches[0]);
            }
        }

        return $tokens;
    }

    /**
     * @return list<string>
     */
    private function urls(string $value): array
    {
        return preg_match_all('~https?://[^\s<>"\']+~u', $value, $matches) ? $matches[0] : [];
    }

    /**
     * @return list<string>
     */
    private function emails(string $value): array
    {
        return preg_match_all('~[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}~u', $value, $matches) ? $matches[0] : [];
    }

    /**
     * @return list<string>
     */
    private function digits(string $value): array
    {
        return preg_match_all('/\d+/u', $value, $matches) ? $matches[0] : [];
    }

    /**
     * @return list<string>
     */
    private function currencyTokens(string $value): array
    {
        $symbols = preg_match_all('/[€$£¥₽₹₺₩₪₫₴₦₱฿₡₲₵₸₼₾]/u', $value, $matches) ? $matches[0] : [];
        $codes = preg_match_all('/\b(?:USD|EUR|GBP|EGP|SAR|AED|KWD|QAR|BHD|OMR|JOD|TRY|CAD|AUD|JPY|CNY|INR|BRL|MXN|ZAR|CHF|SEK|NOK|DKK|PLN)\b/u', $value, $codesMatch) ? $codesMatch[0] : [];

        return array_merge($symbols, $codes);
    }

    /**
     * @return list<string>
     */
    private function skuTokens(string $value): array
    {
        $tokens = preg_match_all('/\b[A-Z0-9]{2,}(?:[-_\/][A-Z0-9]{2,})+\b/u', $value, $matches) ? $matches[0] : [];

        return array_values(array_unique($tokens));
    }

    /**
     * @param  list<string>  $sourceTokens
     * @param  list<string>  $translatedTokens
     * @param  list<string>  $errors
     */
    private function compareTokens(array $sourceTokens, array $translatedTokens, string $label, array &$errors): void
    {
        $sourceTokens = array_values(array_unique($sourceTokens));

        if ($sourceTokens === []) {
            return;
        }

        $translatedLookup = array_flip($translatedTokens);

        foreach ($sourceTokens as $token) {
            if (! isset($translatedLookup[$token])) {
                $errors[] = "The {$label} \"{$token}\" is missing from the translation.";
            }
        }
    }

    /**
     * @param  list<string>  $errors
     */
    private function validateHtml(string $source, string $translated, array &$errors): void
    {
        $sourceTags = $this->tagNames($source);
        $translatedTags = $this->tagNames($translated);

        foreach ($sourceTags as $tag) {
            if (! in_array($tag, $translatedTags, true)) {
                $errors[] = "The HTML tag <{$tag}> is missing from the translation.";
            }
        }

        if (preg_match('/<\s*(script|style|iframe|object|embed)\b/i', $translated)) {
            $errors[] = 'The translation introduced a disallowed HTML tag.';
        }

        if (preg_match('/\son[a-z]+\s*=/i', $translated)) {
            $errors[] = 'The translation introduced an inline event handler.';
        }
    }

    /**
     * @return list<string>
     */
    private function tagNames(string $value): array
    {
        if (! preg_match_all('/<\s*([a-zA-Z][a-zA-Z0-9]*)\b/', $value, $matches)) {
            return [];
        }

        return array_values(array_unique(array_map('strtolower', $matches[1])));
    }
}
