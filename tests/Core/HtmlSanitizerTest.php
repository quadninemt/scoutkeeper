<?php

declare(strict_types=1);

namespace Tests\Core;

use App\Core\HtmlSanitizer;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the HtmlSanitizer wrapper.
 *
 * Verifies that script injection vectors are stripped while legitimate
 * WYSIWYG formatting survives — content sanitized here is rendered
 * with Twig's |raw filter, so this is a security boundary.
 */
class HtmlSanitizerTest extends TestCase
{
    public function testStripsScriptTags(): void
    {
        $result = HtmlSanitizer::sanitize('<p>Hello</p><script>alert(1)</script>');
        $this->assertStringNotContainsString('<script', $result);
        $this->assertStringNotContainsString('alert(1)', $result);
        $this->assertStringContainsString('<p>Hello</p>', $result);
    }

    public function testStripsEventHandlerAttributes(): void
    {
        $result = HtmlSanitizer::sanitize('<img src="x.png" onerror="alert(1)"><b onclick="steal()">bold</b>');
        $this->assertStringNotContainsString('onerror', $result);
        $this->assertStringNotContainsString('onclick', $result);
        $this->assertStringContainsString('<b', $result);
    }

    public function testStripsJavascriptUrls(): void
    {
        $result = HtmlSanitizer::sanitize('<a href="javascript:alert(1)">click</a>');
        $this->assertStringNotContainsString('javascript:', $result);
    }

    public function testStripsIframes(): void
    {
        $result = HtmlSanitizer::sanitize('<iframe src="https://evil.example"></iframe><p>ok</p>');
        $this->assertStringNotContainsString('<iframe', $result);
        $this->assertStringContainsString('<p>ok</p>', $result);
    }

    public function testKeepsCommonFormatting(): void
    {
        $input = '<h2>Title</h2><p>Some <strong>bold</strong> and <em>italic</em> text.</p>'
            . '<ul><li>One</li><li>Two</li></ul>'
            . '<a href="https://example.org">link</a>'
            . '<table><tr><td>cell</td></tr></table>';
        $result = HtmlSanitizer::sanitize($input);

        foreach (['<h2>', '<strong>', '<em>', '<ul>', '<li>', 'https://example.org', '<table>', '<td>'] as $expected) {
            $this->assertStringContainsString($expected, $result);
        }
    }

    public function testKeepsRelativeLinksAndImages(): void
    {
        $result = HtmlSanitizer::sanitize('<a href="/articles/news">news</a><img src="/data/uploads/photo.jpg">');
        $this->assertStringContainsString('href="/articles/news"', $result);
        $this->assertStringContainsString('src="/data/uploads/photo.jpg"', $result);
    }

    public function testHandlesLongContent(): void
    {
        // Default symfony limit is 20k chars — articles can exceed that
        $input = '<p>' . str_repeat('word ', 20000) . '</p>';
        $result = HtmlSanitizer::sanitize($input);
        $this->assertGreaterThan(50000, strlen($result));
    }
}
