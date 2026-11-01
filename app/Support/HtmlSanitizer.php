<?php

declare(strict_types=1);

namespace App\Support;

use DOMAttr;
use DOMDocument;
use DOMElement;
use DOMNode;

/**
 * A conservative HTML allow-list for rich-text content authored in the admin.
 *
 * The editor is a browser `contenteditable`, so whatever it submits is
 * untrusted markup: pasted styles, `on*` handlers, `<script>` tags and
 * `javascript:` links all arrive as ordinary input. Nothing about the form can
 * prevent that, so the server is the only place that can.
 *
 * The strategy is "clean, don't reject": disallowed tags (including `<script>`,
 * `<style>` and `<iframe>`) are dropped, but their text children are kept where
 * that is meaningful, so a paste from a word processor degrades to readable
 * prose instead of being refused or silently emptied. Attributes are stripped
 * wholesale except for a vetted `href` on anchors, an image's own source and alt
 * text, and a small set of layout properties the toolbar emits.
 *
 * This is intentionally a small allow-list, not a general-purpose library: the
 * set of tags matches exactly what the toolbar can produce, and widening it is a
 * deliberate code change rather than a config value a future editor could
 * outgrow unnoticed.
 */
class HtmlSanitizer
{
    /**
     * Allowed tags mapped to the attributes each may keep. An empty list means
     * the tag survives but every attribute on it is removed.
     *
     * @var array<string, list<string>>
     */
    private const ALLOWED = [
        'p' => ['style'],
        'br' => [],
        'hr' => [],
        'strong' => [],
        'b' => [],
        'em' => [],
        'i' => [],
        'u' => [],
        's' => [],
        'ul' => [],
        'ol' => [],
        'li' => [],
        'h2' => ['style'],
        'h3' => ['style'],
        'h4' => ['style'],
        'blockquote' => ['style'],
        'code' => [],
        'pre' => [],
        'a' => ['href'],
        'figure' => ['style'],
        'figcaption' => ['style'],
        'img' => ['src', 'alt', 'width', 'height', 'loading', 'style'],
    ];

    /** Tags that never keep their children — their content is not prose. */
    private const DISCARD_CONTENT = ['script', 'style', 'iframe', 'object', 'embed', 'noscript'];

    /**
     * Schemes an anchor may point at. Relative URLs (no scheme) and fragments
     * are allowed too; this list only constrains absolute links.
     *
     * @var list<string>
     */
    private const ALLOWED_SCHEMES = ['http', 'https', 'mailto', 'tel'];

    /**
     * CSS properties the editor is allowed to emit.
     *
     * Only layout hints the toolbar actually produces survive; a pasted
     * `background:url(javascript:…)` or `position:fixed` is dropped. Values are
     * validated by {@see isSafeStyleValue()}.
     *
     * @var list<string>
     */
    private const ALLOWED_STYLE_PROPERTIES = ['text-align', 'width', 'height', 'max-width'];

    public function clean(?string $html): string
    {
        if ($html === null || trim($html) === '') {
            return '';
        }

        $document = new DOMDocument;

        // `LIBXML_HTML_NOIMPLIED` stops libxml inventing an <html><body> around
        // a fragment, and the XML encoding hint is how a fragment of UTF-8 text
        // survives parsing without being read as Latin-1 (which would mangle
        // every non-ASCII character the author typed).
        $previous = libxml_use_internal_errors(true);

        $loaded = $document->loadHTML(
            '<?xml encoding="UTF-8"><div>'.$html.'</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD,
        );

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if ($loaded === false || $document->documentElement === null) {
            return '';
        }

        $root = $document->documentElement;
        $this->scrub($root);

        $clean = '';
        foreach ($root->childNodes as $child) {
            $clean .= $document->saveHTML($child);
        }

        return trim($clean);
    }

    /**
     * Walk the tree depth-first, normalising every element in place.
     *
     * Children are copied before iteration because unwrapping a node mutates the
     * live child list underneath the iterator.
     */
    private function scrub(DOMNode $node): void
    {
        /** @var list<DOMNode> $children */
        $children = [];
        foreach ($node->childNodes as $child) {
            $children[] = $child;
        }

        foreach ($children as $child) {
            $this->scrubNode($child);
        }
    }

    private function scrubNode(DOMNode $node): void
    {
        if ($node instanceof DOMElement) {
            $this->scrubElement($node);

            return;
        }

        $this->scrub($node);
    }

    private function scrubElement(DOMElement $element): void
    {
        $tag = strtolower($element->tagName);

        if (in_array($tag, self::DISCARD_CONTENT, true)) {
            $element->parentNode?->removeChild($element);

            return;
        }

        if (! array_key_exists($tag, self::ALLOWED)) {
            // Unwrap: keep the text and any allowed descendants, drop the tag.
            // The children are captured first because unwrapping moves them out
            // of the element, and they still need to be scrubbed in their new
            // position.
            $moved = [];
            foreach ($element->childNodes as $child) {
                $moved[] = $child;
            }

            $this->unwrap($element);

            foreach ($moved as $child) {
                $this->scrubNode($child);
            }

            return;
        }

        $this->stripAttributes($element, $tag);

        // An image whose source was refused is not content: it would render as a
        // broken icon and still ship the author's alt text. Drop the element
        // rather than keeping a shell that points nowhere.
        if ($tag === 'img' && ! $element->hasAttribute('src')) {
            $element->parentNode?->removeChild($element);

            return;
        }

        $this->scrub($element);
    }

    private function stripAttributes(DOMElement $element, string $tag): void
    {
        $allowed = self::ALLOWED[$tag];

        /** @var list<DOMAttr> $attributes */
        $attributes = [];
        foreach ($element->attributes as $attribute) {
            $attributes[] = $attribute;
        }

        foreach ($attributes as $attribute) {
            $name = strtolower($attribute->name);

            if (! in_array($name, $allowed, true)) {
                $element->removeAttribute($attribute->name);

                continue;
            }

            $safe = match ($name) {
                'href' => $this->isSafeHref($attribute->value),
                'src' => $this->isSafeImageSrc($attribute->value),
                'style' => $this->cleanStyle($attribute->value) !== '',
                'loading' => strtolower(trim($attribute->value)) === 'lazy',
                'width', 'height' => preg_match('/^\d{1,4}$/', trim($attribute->value)) === 1,
                default => true,
            };

            if (! $safe) {
                $element->removeAttribute($attribute->name);

                continue;
            }

            if ($name === 'style') {
                $element->setAttribute('style', $this->cleanStyle($attribute->value));
            }
        }
    }

    /**
     * Keep only declared properties whose values are bounded layout hints.
     *
     * A width/height may be a percentage or a pixel value; `max-width: 100%` is
     * what keeps an uploaded image inside a narrow article column. Everything
     * else — including `url()`, `expression()` and CSS escapes — is discarded.
     */
    private function cleanStyle(string $style): string
    {
        $kept = [];

        foreach (explode(';', $style) as $declaration) {
            if (! str_contains($declaration, ':')) {
                continue;
            }

            [$property, $value] = array_map('trim', explode(':', $declaration, 2));
            $property = strtolower($property);
            $value = strtolower($value);

            if (! in_array($property, self::ALLOWED_STYLE_PROPERTIES, true)) {
                continue;
            }

            if ($this->isSafeStyleValue($property, $value)) {
                $kept[] = $property.': '.$value;
            }
        }

        return implode('; ', $kept);
    }

    private function isSafeStyleValue(string $property, string $value): bool
    {
        if ($property === 'text-align') {
            return in_array($value, ['left', 'right', 'center', 'justify'], true);
        }

        if ($property === 'max-width') {
            return $value === '100%';
        }

        return preg_match('/^\d{1,3}%$/', $value) === 1
            || preg_match('/^\d{1,4}px$/', $value) === 1;
    }

    /**
     * Inline images may only point at our own storage or an absolute https URL.
     *
     * `data:` is rejected on purpose: a base64 payload is not an upload, it is
     * a document body that grows without bound and bypasses the storage path the
     * platform audits and deletes with the article.
     */
    private function isSafeImageSrc(string $src): bool
    {
        $src = trim($src);

        if ($src === '') {
            return false;
        }

        if (str_starts_with($src, '/')) {
            return ! str_starts_with($src, '//');
        }

        $scheme = parse_url($src, PHP_URL_SCHEME);

        return $scheme !== null
            && $scheme !== false
            && in_array(strtolower($scheme), ['http', 'https'], true);
    }

    private function isSafeHref(string $href): bool
    {
        $href = trim($href);

        if ($href === '') {
            return false;
        }

        // Fragment and relative links have no scheme to abuse.
        if (str_starts_with($href, '#') || str_starts_with($href, '/') || str_starts_with($href, '?')) {
            return true;
        }

        $scheme = parse_url($href, PHP_URL_SCHEME);

        if ($scheme === null || $scheme === false) {
            return true;
        }

        return in_array(strtolower($scheme), self::ALLOWED_SCHEMES, true);
    }

    /**
     * Replace an element with its own children, preserving order and text.
     */
    private function unwrap(DOMElement $element): void
    {
        $parent = $element->parentNode;

        if ($parent === null) {
            return;
        }

        while ($element->firstChild !== null) {
            $parent->insertBefore($element->firstChild, $element);
        }

        $parent->removeChild($element);
    }
}
