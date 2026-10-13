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
 * wholesale except for a vetted `href` on anchors.
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
        'p' => [],
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
        'h2' => [],
        'h3' => [],
        'h4' => [],
        'blockquote' => [],
        'code' => [],
        'pre' => [],
        'a' => ['href'],
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

            if ($name === 'href' && ! $this->isSafeHref($attribute->value)) {
                $element->removeAttribute($attribute->name);
            }
        }
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
