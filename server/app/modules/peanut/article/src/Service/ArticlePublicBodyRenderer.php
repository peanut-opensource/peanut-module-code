<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Article\Service;

use PeanutAdmin\Modules\RichText\Contract\RichTextProjection;

/** Public HTML for the existing Article clients. File nodes never acquire anonymous delivery authority. */
final class ArticlePublicBodyRenderer
{
    public function render(array $structured): string
    {
        $projection = RichTextProjection::fromStructured($structured);
        $content = json_decode($projection->json, true, 64, JSON_THROW_ON_ERROR);
        return $this->node($content['content']);
    }

    private function node(array $node): string
    {
        $type = $node['type'];
        if (in_array($type, ['image', 'video', 'audio', 'file', 'media'], true)) {
            return '<p>附件需授权查看</p>';
        }
        if ($type === 'text') {
            $text = self::escape($node['text']);
            foreach ($node['marks'] ?? [] as $mark) {
                $attrs = $mark['attrs'] ?? [];
                $text = match ($mark['type']) {
                    'bold' => '<strong>' . $text . '</strong>', 'italic' => '<em>' . $text . '</em>',
                    'strike' => '<s>' . $text . '</s>', 'underline' => '<u>' . $text . '</u>', 'code' => '<code>' . $text . '</code>',
                    'link' => self::link($attrs, $text),
                    'textStyle' => isset($attrs['color']) ? '<span style="color:' . self::escape($attrs['color']) . '">' . $text . '</span>' : $text,
                };
            }
            return $text;
        }
        if ($type === 'hardBreak') { return '<br>'; }
        if ($type === 'horizontalRule') { return '<hr>'; }
        $body = '';
        foreach ($node['content'] ?? [] as $child) { $body .= $this->node($child); }
        if ($type === 'doc') { return $body; }
        $attrs = $node['attrs'] ?? [];
        $tag = match ($type) {
            'paragraph' => 'p', 'heading' => 'h' . ($attrs['level'] ?? 1), 'blockquote' => 'blockquote',
            'bulletList' => 'ul', 'orderedList' => 'ol', 'listItem' => 'li', 'codeBlock' => 'pre',
            'table' => 'table', 'tableRow' => 'tr', 'tableCell' => 'td', 'tableHeader' => 'th',
        };
        $attributes = '';
        if (isset($attrs['textAlign'])) { $attributes .= ' style="text-align:' . self::escape($attrs['textAlign']) . '"'; }
        if ($type === 'orderedList' && isset($attrs['start'])) { $attributes .= ' start="' . $attrs['start'] . '"'; }
        foreach (['colspan', 'rowspan'] as $key) {
            if (isset($attrs[$key])) { $attributes .= ' ' . $key . '="' . $attrs[$key] . '"'; }
        }
        if ($type === 'codeBlock') { $body = '<code>' . $body . '</code>'; }
        if ($type === 'table') { $body = '<tbody>' . $body . '</tbody>'; }
        return '<' . $tag . $attributes . '>' . $body . '</' . $tag . '>';
    }

    private static function link(array $attrs, string $text): string
    {
        // These are the existing RichTextResourceService controlled-File URL paths, not ordinary hyperlinks.
        $path = rawurldecode((string) parse_url($attrs['href'], PHP_URL_PATH));
        if (str_contains($path, '/storage/') || str_contains($path, '/api/storage/delivery')) {
            return $text . '（附件需授权查看）';
        }
        return '<a href="' . self::escape($attrs['href']) . '" target="' . self::escape($attrs['target'] ?? '_self')
            . '" rel="noopener noreferrer">' . $text . '</a>';
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }
}
