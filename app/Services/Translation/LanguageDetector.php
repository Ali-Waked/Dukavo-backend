<?php

declare(strict_types=1);

namespace App\Services\Translation;

/**
 * Best-effort source-language detection.
 *
 * It is deliberately conservative: a wrong guess would machine-translate from
 * the wrong language, which is worse than asking the merchant to pick. A
 * confident script identifies Arabic, Hebrew, Cyrillic, Greek, Devanagari and
 * the CJK families; Latin text is scored against a small stop-word table and
 * only wins when one language dominates.
 *
 * The detected locale is never silently replaced by the store or dashboard
 * language. A low-confidence result returns null so the caller can require an
 * explicit source language.
 */
final class LanguageDetector
{
    /**
     * Minimum share of the winning language's evidence before detection is
     * trusted. Below this the merchant must choose.
     */
    private const MIN_CONFIDENCE = 0.35;

    /**
     * Very common function words, enough to separate the usual Latin-script
     * storefront languages without a heavy model.
     *
     * @var array<string, list<string>>
     */
    private const STOP_WORDS = [
        'en' => ['the', 'and', 'for', 'with', 'this', 'that', 'from', 'your', 'our', 'you', 'are', 'not', 'free', 'shipping', 'delivery'],
        'fr' => ['le', 'la', 'les', 'des', 'et', 'pour', 'avec', 'votre', 'notre', 'est', 'une', 'dans', 'livraison', 'gratuit'],
        'es' => ['el', 'la', 'los', 'las', 'y', 'para', 'con', 'su', 'nuestro', 'es', 'una', 'envío', 'gratis', 'entrega'],
        'de' => ['der', 'die', 'das', 'und', 'für', 'mit', 'ihr', 'unser', 'ist', 'eine', 'nicht', 'versand', 'kostenlos'],
        'it' => ['il', 'la', 'gli', 'e', 'per', 'con', 'tuo', 'nostro', 'è', 'una', 'spedizione', 'gratuita', 'consegna'],
        'pt' => ['o', 'a', 'os', 'as', 'e', 'para', 'com', 'seu', 'nosso', 'é', 'uma', 'envio', 'grátis', 'entrega'],
        'nl' => ['de', 'het', 'en', 'voor', 'met', 'uw', 'ons', 'is', 'een', 'niet', 'verzending', 'gratis', 'levering'],
        'tr' => ['ve', 'için', 'ile', 'sizin', 'bizim', 'bir', 'değil', 'ücretsiz', 'kargo', 'teslimat'],
        'id' => ['dan', 'untuk', 'dengan', 'anda', 'kami', 'adalah', 'tidak', 'gratis', 'pengiriman', 'gratis'],
        'pl' => ['i', 'dla', 'z', 'twoja', 'nasz', 'jest', 'nie', 'dostawa', 'bezpłatna'],
        'sv' => ['och', 'för', 'med', 'din', 'vår', 'är', 'inte', 'frakt', 'gratis'],
        'da' => ['og', 'for', 'med', 'din', 'vores', 'er', 'ikke', 'levering', 'gratis'],
    ];

    /**
     * @var array<string, string>
     */
    private const SCRIPT_LANGUAGES = [
        'Arabic' => 'ar',
        'Hebrew' => 'he',
        'Cyrillic' => 'ru',
        'Greek' => 'el',
        'Devanagari' => 'hi',
        'Bengali' => 'bn',
        'Thai' => 'th',
    ];

    /**
     * @param  list<string|null>  $samples
     * @param  list<string>  $candidates  Enabled locale codes to prefer.
     * @return array{locale: string|null, confidence: float, method: string}
     */
    public function detect(array $samples, array $candidates = []): array
    {
        $text = $this->normalise($samples, 6000);

        if ($text === '') {
            return ['locale' => null, 'confidence' => 0.0, 'method' => 'none'];
        }

        $candidates = $this->candidates($candidates);
        $script = $this->scriptDetection($text, $candidates);

        if ($script !== null) {
            return $script;
        }

        return $this->stopWordDetection($text, $candidates);
    }

    /**
     * @param  array{locale: string|null, confidence: float, method: string}  $detection
     */
    public function isConfident(array $detection): bool
    {
        return $detection['locale'] !== null && $detection['confidence'] >= self::MIN_CONFIDENCE;
    }

    /**
     * @param  list<string|null>  $samples
     */
    private function normalise(array $samples, int $limit): string
    {
        $parts = [];

        foreach ($samples as $sample) {
            if (is_string($sample) && trim($sample) !== '') {
                $parts[] = $sample;
            }
        }

        $text = trim(preg_replace('/\s+/u', ' ', implode(' ', $parts)) ?? '');

        return mb_substr($text, 0, $limit);
    }

    /**
     * @param  list<string>  $candidates
     * @return list<string>
     */
    private function candidates(array $candidates): array
    {
        $candidates = array_values(array_unique(array_map(
            static fn (mixed $code): string => strtolower(trim((string) $code)),
            $candidates,
        )));

        $candidates = array_values(array_filter($candidates, static fn (string $code): bool => $code !== ''));

        return $candidates === []
            ? array_map('strval', (array) config('dukavo.locales.supported', ['en']))
            : $candidates;
    }

    /**
     * @param  list<string>  $candidates
     * @return array{locale: string|null, confidence: float, method: string}|null
     */
    private function scriptDetection(string $text, array $candidates): ?array
    {
        $length = max(1, mb_strlen($text));
        $counts = [];

        foreach (array_keys(self::SCRIPT_LANGUAGES) as $script) {
            $counts[$script] = preg_match_all('/\p{'.$script.'}/u', $text) ?: 0;
        }

        $counts['Han'] = preg_match_all('/\p{Han}/u', $text) ?: 0;
        $counts['Hiragana'] = preg_match_all('/\p{Hiragana}/u', $text) ?: 0;
        $counts['Katakana'] = preg_match_all('/\p{Katakana}/u', $text) ?: 0;
        $counts['Hangul'] = preg_match_all('/\p{Hangul}/u', $text) ?: 0;

        $best = null;
        $bestCount = 0;

        foreach ($counts as $script => $count) {
            if ($count > $bestCount) {
                $best = $script;
                $bestCount = $count;
            }
        }

        if ($best === null || $bestCount === 0) {
            return null;
        }

        $locale = match ($best) {
            'Han' => ($counts['Hiragana'] + $counts['Katakana']) > 0 ? 'ja' : 'zh',
            'Hiragana', 'Katakana' => 'ja',
            'Hangul' => 'ko',
            default => self::SCRIPT_LANGUAGES[$best] ?? null,
        };

        if ($locale === null) {
            return null;
        }

        // Arabic script is shared by Arabic, Persian and Urdu. Prefer the
        // merchant's enabled locale when it is one of them; otherwise Arabic
        // is the safest default for this catalogue.
        if ($locale === 'ar' && ! in_array('ar', $candidates, true)) {
            foreach (['fa', 'ur'] as $alternate) {
                if (in_array($alternate, $candidates, true)) {
                    $locale = $alternate;

                    break;
                }
            }
        }

        if (! in_array($locale, $candidates, true)) {
            return [
                'locale' => $locale,
                'confidence' => 0.5,
                'method' => 'script',
            ];
        }

        $confidence = min(0.99, $bestCount / $length);

        return [
            'locale' => $locale,
            'confidence' => round(max(0.5, $confidence), 2),
            'method' => 'script',
        ];
    }

    /**
     * @param  list<string>  $candidates
     * @return array{locale: string|null, confidence: float, method: string}
     */
    private function stopWordDetection(string $text, array $candidates): array
    {
        $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $counts = [];

        foreach ($candidates as $locale) {
            $stopWords = self::STOP_WORDS[$locale] ?? null;

            if ($stopWords === null) {
                continue;
            }

            $lookup = array_flip($stopWords);
            $hits = 0;

            foreach ($words as $word) {
                if (isset($lookup[$word])) {
                    $hits++;
                }
            }

            if ($hits > 0) {
                $counts[$locale] = $hits;
            }
        }

        if ($counts === []) {
            return ['locale' => null, 'confidence' => 0.0, 'method' => 'stopwords'];
        }

        arsort($counts);
        $locale = (string) array_key_first($counts);
        $best = (int) reset($counts);
        $total = array_sum($counts);
        $confidence = $total > 0 ? $best / $total : 0.0;

        if ($confidence < self::MIN_CONFIDENCE) {
            return ['locale' => null, 'confidence' => round($confidence, 2), 'method' => 'stopwords'];
        }

        return ['locale' => $locale, 'confidence' => round($confidence, 2), 'method' => 'stopwords'];
    }
}
