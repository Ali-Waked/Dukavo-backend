<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\HtmlSanitizer;
use PHPUnit\Framework\TestCase;

/**
 * The rich-text allow-list.
 *
 * The editor is a `contenteditable`, so the sanitiser is the only thing standing
 * between a pasted document and stored XSS. These tests pin both halves of that
 * contract: the formatting the toolbar can produce survives unchanged, and
 * anything that could execute is removed rather than merely hidden.
 */
class HtmlSanitizerTest extends TestCase
{
    private HtmlSanitizer $sanitizer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sanitizer = new HtmlSanitizer;
    }

    public function test_the_toolbar_markup_survives(): void
    {
        $html = '<h2>Heading</h2><h3>Sub</h3>'
            .'<p><strong>bold</strong> <em>italic</em> <u>under</u> <s>gone</s></p>'
            .'<ul><li>one</li></ul><ol><li>two</li></ol>'
            .'<blockquote>Quote</blockquote><hr>'
            .'<p><a href="https://example.com">link</a></p>';

        $clean = $this->sanitizer->clean($html);

        foreach (['<h2>', '<h3>', '<strong>', '<em>', '<u>', '<s>', '<ul>', '<ol>', '<li>', '<blockquote>', '<hr>'] as $tag) {
            $this->assertStringContainsString($tag, $clean);
        }

        $this->assertStringContainsString('href="https://example.com"', $clean);
    }

    public function test_a_script_and_its_contents_are_removed(): void
    {
        $clean = $this->sanitizer->clean('<p>Safe</p><script>alert(1)</script><style>body{}</style>');

        $this->assertStringContainsString('Safe', $clean);
        $this->assertStringNotContainsString('script', $clean);
        $this->assertStringNotContainsString('alert', $clean);
        $this->assertStringNotContainsString('style', $clean);
    }

    public function test_event_handlers_and_javascript_urls_are_stripped(): void
    {
        $clean = $this->sanitizer->clean(
            '<p onclick="evil()" style="background:url(javascript:alert(1))">Text '
            .'<a href="javascript:alert(1)" onmouseover="evil()">bad</a></p>',
        );

        $this->assertStringContainsString('Text', $clean);
        $this->assertStringContainsString('bad', $clean);
        $this->assertStringNotContainsString('onclick', $clean);
        $this->assertStringNotContainsString('onmouseover', $clean);
        $this->assertStringNotContainsString('javascript:', $clean);
        $this->assertStringNotContainsString('background', $clean);
    }

    public function test_a_relative_image_with_alt_and_bounded_style_survives(): void
    {
        $clean = $this->sanitizer->clean(
            '<figure style="text-align: center">'
            .'<img src="/storage/blogs/content/chart.png" alt="Revenue chart" '
            .'style="width: 60%; max-width: 100%; position: fixed" loading="lazy">'
            .'<figcaption>A caption</figcaption>'
            .'</figure>',
        );

        $this->assertStringContainsString('<figure', $clean);
        $this->assertStringContainsString('<figcaption>', $clean);
        $this->assertStringContainsString('src="/storage/blogs/content/chart.png"', $clean);
        $this->assertStringContainsString('alt="Revenue chart"', $clean);
        $this->assertStringContainsString('text-align: center', $clean);
        $this->assertStringContainsString('width: 60%', $clean);
        $this->assertStringContainsString('max-width: 100%', $clean);
        $this->assertStringNotContainsString('position', $clean);
    }

    public function test_a_data_uri_image_is_rejected(): void
    {
        $clean = $this->sanitizer->clean(
            '<p>Before</p><img src="data:image/png;base64,AAAA" alt="inline">',
        );

        $this->assertStringContainsString('Before', $clean);
        $this->assertStringNotContainsString('data:', $clean);
        $this->assertStringNotContainsString('<img', $clean);
    }

    public function test_an_unknown_tag_is_unwrapped_but_its_text_is_kept(): void
    {
        $clean = $this->sanitizer->clean('<section><p>Kept <span>text</span></p></section>');

        $this->assertStringNotContainsString('<section', $clean);
        $this->assertStringNotContainsString('<span', $clean);
        $this->assertStringContainsString('Kept text', $clean);
    }

    public function test_utf8_text_is_preserved(): void
    {
        $clean = $this->sanitizer->clean('<p>مرحبا بالعالم</p>');

        $this->assertSame('<p>مرحبا بالعالم</p>', $clean);
    }

    public function test_empty_input_cleans_to_an_empty_string(): void
    {
        $this->assertSame('', $this->sanitizer->clean(null));
        $this->assertSame('', $this->sanitizer->clean('   '));
    }
}
