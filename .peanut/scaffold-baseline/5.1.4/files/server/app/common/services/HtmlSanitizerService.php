<?php

declare(strict_types=1);

namespace app\common\services;

use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

/** The authoritative allow-list sanitizer for persisted rich text. */
final class HtmlSanitizerService
{
    private static ?HtmlSanitizer $sanitizer = null;

    public static function sanitize(string $html): string
    {
        if ($html === '') {
            return '';
        }
        return self::sanitizer()->sanitize($html);
    }

    private static function sanitizer(): HtmlSanitizer
    {
        if (self::$sanitizer instanceof HtmlSanitizer) {
            return self::$sanitizer;
        }

        $config = (new HtmlSanitizerConfig())
            ->allowLinkSchemes(['http', 'https', 'mailto', 'tel'])
            ->allowRelativeLinks()
            ->allowMediaSchemes(['http', 'https'])
            ->allowRelativeMedias()
            ->withMaxInputLength(250_000);

        foreach ([
            'p', 'br', 'strong', 'b', 'em', 'i', 'u', 's', 'blockquote',
            'pre', 'code', 'ul', 'ol', 'li', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
            'div', 'span', 'figure', 'figcaption', 'hr',
        ] as $element) {
            $config = $config->allowElement($element);
        }
        foreach (['table', 'thead', 'tbody', 'tfoot'] as $element) {
            $config = $config->allowElement($element);
        }
        $config = $config
            ->allowElement('tr')
            ->allowElement('th', ['colspan', 'rowspan'])
            ->allowElement('td', ['colspan', 'rowspan'])
            ->allowElement('a', ['href', 'title', 'target', 'rel'])
            ->forceAttribute('a', 'rel', 'noopener noreferrer')
            ->allowElement('img', ['src', 'alt', 'title', 'width', 'height', 'loading'])
            ->allowElement('video', ['src', 'poster', 'controls', 'preload', 'width', 'height'])
            ->allowElement('source', ['src', 'type']);

        return self::$sanitizer = new HtmlSanitizer($config);
    }

    private function __construct() {}
}
