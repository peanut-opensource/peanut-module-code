<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\RichText\Contract;

use app\common\exception\BusinessException;

/** Validated JSON projection. It never creates or reconstructs Yjs state. */
final readonly class RichTextProjection
{
    public const SCHEMA = 'peanut.richtext/1';
    private function __construct(public string $json, public array $fileReferences) {}
    public static function empty(): self
    {
        return self::fromStructured(['schemaVersion' => self::SCHEMA,'editorModel' => 'tiptap-prosemirror',
            'content' => ['type' => 'doc','content' => [['type' => 'paragraph']]],'annotations' => []]);
    }
    public static function fromStructured(array $value): self
    {
        if (array_diff(array_keys($value), ['schemaVersion','editorModel','content','annotations']) !== []
            || ($value['schemaVersion'] ?? null) !== self::SCHEMA || ($value['editorModel'] ?? null) !== 'tiptap-prosemirror'
            || !is_array($value['content'] ?? null) || ($value['content']['type'] ?? null) !== 'doc'
            || ($value['annotations'] ?? null) !== []) {
            self::invalid();
        }
        try {
            $json = json_encode(self::canonical($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE, 64);
        } catch (\Throwable) {
            self::invalid();
        }
        if (strlen($json) > 2 * 1024 * 1024) {
            self::invalid();
        }
        $references = [];
        $nodes = 0;
        self::node($value['content'], $references, $nodes, 0);
        if (count($references) > 1000) {
            self::invalid();
        }
        ksort($references, SORT_STRING);
        return new self($json, array_values($references));
    }
    private static function node(array $node, array &$references, int &$nodes, int $depth): void
    {
        if (++$nodes > 50000 || $depth > 60 || array_diff(array_keys($node), ['type','content','text','attrs','marks']) !== []) {
            self::invalid();
        }
        $type = $node['type'] ?? null;
        if (!in_array($type, ['doc','paragraph','text','heading','blockquote','bulletList','orderedList','listItem','codeBlock','hardBreak','horizontalRule','table','tableRow','tableCell','tableHeader','image','video','audio','file','media'], true)) {
            self::invalid();
        }
        if (isset($node['text']) && ($type !== 'text' || !is_string($node['text']))) {
            self::invalid();
        }
        $attrs = $node['attrs'] ?? [];
        if (!is_array($attrs)) {
            self::invalid();
        }
        if (in_array($type, ['image','video','audio','file','media'], true)) {
            if (array_diff(array_keys($attrs), ['fileKey','kind','variantKey','alt','title']) !== []
                || !is_string($attrs['fileKey'] ?? null) || preg_match('/^file_[0-9a-f]{32}$/D', $attrs['fileKey']) !== 1
                || !in_array($attrs['kind'] ?? null, ['image','video','audio','file'], true)
                || isset($attrs['variantKey']) && (!is_string($attrs['variantKey']) || preg_match('/^[a-z][a-z0-9-]{0,31}$/D', $attrs['variantKey']) !== 1)) {
                self::invalid();
            }
            foreach (['alt','title'] as $field) {
                if (isset($attrs[$field]) && (!is_string($attrs[$field]) || strlen($attrs[$field]) > 1000)) {
                    self::invalid();
                }
            }
            $references[$attrs['fileKey']] = ['file_key' => $attrs['fileKey'],'variant_key' => $attrs['variantKey'] ?? null];
        } else {
            $allowed = match ($type) {
                'heading' => ['level','textAlign'],'paragraph' => ['textAlign'],'orderedList' => ['start'],
                'codeBlock' => ['language'],'tableCell','tableHeader' => ['colspan','rowspan','colwidth'],default => [],
            };
            if (array_diff(array_keys($attrs), $allowed) !== []) {
                self::invalid();
            }
            foreach ($attrs as $name => $val) {
                $valid = match ($name) {
                    'level' => is_int($val) && $val >= 1 && $val <= 6,
                    'textAlign' => $val === null || in_array($val, ['left','right','center','justify'], true),
                    'start' => is_int($val) && $val >= 1 && $val <= 1000000,
                    'language' => $val === null || is_string($val) && preg_match('/^[a-zA-Z0-9_+.-]{0,64}$/D', $val) === 1,
                    'colspan','rowspan' => is_int($val) && $val >= 1 && $val <= 100,
                    'colwidth' => $val === null || is_array($val) && array_is_list($val) && count($val) <= 100 && count(array_filter($val, fn($n) => is_int($n) && $n >= 1 && $n <= 10000)) === count($val),
                    default => false,
                };
                if (!$valid) {
                    self::invalid();
                }
            }
        }
        $marks = $node['marks'] ?? [];
        if (!is_array($marks) || !array_is_list($marks)) {
            self::invalid();
        }
        foreach ($marks as $mark) {
            if (!is_array($mark) || array_diff(array_keys($mark), ['type','attrs']) !== [] || !in_array($mark['type'] ?? null, ['bold','italic','strike','underline','code','link','textStyle'], true)) {
                self::invalid();
            }
            $attributes = $mark['attrs'] ?? [];
            if (!is_array($attributes)) {
                self::invalid();
            }
            if ($mark['type'] === 'link') {
                $href = $attributes['href'] ?? null;
                if (!is_string($href) || strlen($href) > 2048 || array_diff(array_keys($attributes), ['href','target','rel','class']) !== []
                    || !in_array(strtolower((string) parse_url($href, PHP_URL_SCHEME)), ['https','http','mailto'], true)
                    || in_array(strtolower((string) parse_url($href, PHP_URL_SCHEME)), ['https','http'], true) && !is_string(parse_url($href, PHP_URL_HOST))
                    || str_contains($href, '/storage/delivery') || preg_match('/[\x00-\x20]/', $href) === 1
                    || isset($attributes['target']) && !in_array($attributes['target'], ['_blank','_self'], true)
                    || isset($attributes['rel']) && $attributes['rel'] !== null && !in_array($attributes['rel'], ['noopener noreferrer','noopener noreferrer nofollow'], true)
                    || isset($attributes['class']) && $attributes['class'] !== null) {
                    self::invalid();
                }
            } elseif ($mark['type'] === 'textStyle') {
                if (array_diff(array_keys($attributes), ['color']) !== [] || isset($attributes['color']) && (!is_string($attributes['color']) || preg_match('/^#[0-9a-fA-F]{3,8}$/D', $attributes['color']) !== 1)) {
                    self::invalid();
                }
            } elseif ($attributes !== []) {
                self::invalid();
            }
        }
        $children = $node['content'] ?? [];
        if (!is_array($children) || !array_is_list($children) || ($type === 'text' && $children !== [])) {
            self::invalid();
        }
        $inline = ['text','hardBreak'];
        $blocks = ['paragraph','heading','blockquote','bulletList','orderedList','codeBlock','horizontalRule','table','image','video','audio','file','media'];
        $allowedChildren = match ($type) {
            'doc','blockquote','tableCell','tableHeader' => $blocks,
            'paragraph','heading' => $inline,'codeBlock' => ['text'],'bulletList','orderedList' => ['listItem'],'listItem' => $blocks,
            'table' => ['tableRow'],'tableRow' => ['tableCell','tableHeader'],default => [],
        };
        if (in_array($type, ['doc','blockquote','bulletList','orderedList','listItem','table','tableRow','tableCell','tableHeader'], true) && $children === []
            || $type === 'listItem' && ($children[0]['type'] ?? null) !== 'paragraph'
            || $type === 'text' && (!is_string($node['text'] ?? null) || $node['text'] === '')
            || !in_array($type, $inline, true) && $marks !== []) {
            self::invalid();
        }
        foreach ($children as $child) {
            if (!is_array($child) || !in_array($child['type'] ?? null, $allowedChildren, true)
                || $type === 'codeBlock' && ($child['marks'] ?? []) !== []) {
                self::invalid();
            }self::node($child, $references, $nodes, $depth + 1);
        }
    }
    public static function canonical(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (!array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as &$item) {
            $item = self::canonical($item);
        }
        return $value;
    }
    private static function invalid(): never
    {
        throw BusinessException::invalid('RICH_TEXT_PROJECTION_INVALID', '富文本结构、大小或资源引用无效');
    }
}
