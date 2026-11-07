<?php

declare(strict_types=1);

use app\common\exception\BusinessException;
use PeanutAdmin\Modules\RichText\Contract\RichTextProjection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 4) . '/vendor/autoload.php';

final class RichTextPlainTextProjectionTest extends TestCase
{
    #[DataProvider('projections')]
    public function testStablePublicProjection(array $nodes, string $text, int $codePoints): void
    {
        $input = ['schemaVersion' => RichTextProjection::SCHEMA, 'editorModel' => 'tiptap-prosemirror',
            'content' => ['type' => 'doc', 'content' => $nodes], 'annotations' => []];
        $projection = RichTextProjection::fromStructured($input);
        self::assertSame('peanut.richtext.plain-text/1', RichTextProjection::TEXT_PROFILE);
        self::assertSame($text, $projection->plainText());
        self::assertSame($codePoints, mb_strlen($projection->plainText(), 'UTF-8'));
        self::assertSame($projection->json, RichTextProjection::fromStructured($input)->json);
        self::assertSame($text, RichTextProjection::fromStructured(json_decode($projection->json, true, 64, JSON_THROW_ON_ERROR))->plainText());
    }

    public static function projections(): array
    {
        $text = static fn(string $value): array => ['type' => 'text', 'text' => $value];
        $paragraph = static fn(string $value): array => ['type' => 'paragraph', 'content' => $value === '' ? [] : [$text($value)]];
        return [
            'empty paragraph' => [[$paragraph('')], '', 0],
            'empty blocks retained' => [[$paragraph(''), $paragraph('A'), $paragraph('')], "\nA\n", 3],
            'Chinese astral combining preserved' => [[$paragraph("中文😀e\u{0301}")], "中文😀e\u{0301}", 5],
            'inline marks do not split' => [[['type' => 'paragraph', 'content' => [
                $text('A'), ['type' => 'text', 'text' => ' B ', 'marks' => [['type' => 'bold']]], $text('C'),
            ]]], 'A B C', 5],
            'explicit hard breaks' => [[['type' => 'paragraph', 'content' => [
                ['type' => 'hardBreak'], $text('A'), ['type' => 'hardBreak'],
            ]]], "\nA\n", 3],
            'nested blocks join once' => [[['type' => 'blockquote', 'content' => [
                $paragraph('A'), ['type' => 'bulletList', 'content' => [
                    ['type' => 'listItem', 'content' => [$paragraph('B'), $paragraph('C')]],
                    ['type' => 'listItem', 'content' => [$paragraph('D')]],
                ]],
            ]], $paragraph('E')], "A\nB\nC\nD\nE", 9],
            'table multiple paragraphs and empty cell' => [[['type' => 'table', 'content' => [
                ['type' => 'tableRow', 'content' => [
                    ['type' => 'tableHeader', 'content' => [$paragraph('A'), $paragraph('B')]],
                    ['type' => 'tableCell', 'content' => [$paragraph('C')]],
                ]],
                ['type' => 'tableRow', 'content' => [
                    ['type' => 'tableCell', 'content' => [$paragraph('')]],
                    ['type' => 'tableCell', 'content' => [$paragraph('D')]],
                ]],
            ]]], "A\nB\tC\n\tD", 8],
            'media uses one object replacement' => [[$paragraph('A'),
                ['type' => 'media', 'attrs' => ['fileKey' => 'file_' . str_repeat('a', 32), 'kind' => 'image', 'alt' => 'Not inline text']],
                ['type' => 'horizontalRule'], $paragraph('B')], "A\n\u{FFFC}\n\u{FFFC}\nB", 7],
            'code whitespace retained' => [[['type' => 'codeBlock', 'content' => [$text(" A\n\n B ")]]], " A\n\n B ", 7],
        ];
    }

    public function testExistingValidationStillRejectsAnnotations(): void
    {
        $value = json_decode(RichTextProjection::empty()->json, true, 64, JSON_THROW_ON_ERROR);
        $value['annotations'] = [['id' => 'untrusted']];
        try {
            RichTextProjection::fromStructured($value);
            self::fail('Annotations were accepted');
        } catch (BusinessException $error) {
            self::assertSame('RICH_TEXT_PROJECTION_INVALID', $error->errorCode);
        }
    }
}
