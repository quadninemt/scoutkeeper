<?php

declare(strict_types=1);

namespace App\Core;

use Symfony\Component\HtmlSanitizer\HtmlSanitizer as SymfonySanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

/**
 * HTML sanitizer for user-authored rich text (articles, T&Cs, notices).
 *
 * Wraps symfony/html-sanitizer with an allow-list suited to WYSIWYG
 * content: formatting, headings, lists, links, images, and tables.
 * Script tags, event handlers, and dangerous URL schemes are stripped.
 * Content sanitized here is safe to render with Twig's |raw filter.
 */
class HtmlSanitizer
{
    private static ?SymfonySanitizer $sanitizer = null;

    /**
     * Sanitize an HTML fragment for safe storage and rendering.
     */
    public static function sanitize(string $html): string
    {
        if (self::$sanitizer === null) {
            $config = (new HtmlSanitizerConfig())
                // Allow all W3C-sanitizer "safe" elements (formatting, lists,
                // tables, links, media) — blocks script, iframe, form inputs,
                // event-handler attributes, and javascript: URLs
                ->allowSafeElements()
                ->allowRelativeLinks()
                ->allowRelativeMedias()
                // WYSIWYG editors emit inline styles for alignment/colour
                ->allowAttribute('style', '*')
                // Default max input length is 20k chars; articles can be longer
                ->withMaxInputLength(1_000_000);

            self::$sanitizer = new SymfonySanitizer($config);
        }

        return self::$sanitizer->sanitize($html);
    }
}
