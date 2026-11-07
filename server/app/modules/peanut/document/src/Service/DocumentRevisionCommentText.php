<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Document\Service;

use app\common\exception\BusinessException;
use PeanutAdmin\Modules\Document\Contract\VersionSnapshot;
use PeanutAdmin\Modules\RichText\Contract\RichTextProjection;

/** Binds RichText's public projection; it does not interpret the editor tree. */
final readonly class DocumentRevisionCommentText
{
    public const OFFSET_UNIT = 'unicode-code-point';
    public const MAX_QUOTE_LENGTH = 4096;

    public static function source(VersionSnapshot $snapshot): array
    {
        if ($snapshot->contentKind !== 'rich_text' || $snapshot->structuredContent === null) {
            throw BusinessException::invalid('DOCUMENT_COMMENT_SOURCE_UNSUPPORTED', '评论需要已保存的富文本固定版本');
        }
        $text = RichTextProjection::fromStructured($snapshot->structuredContent)->plainText();
        return [
            'document_id' => $snapshot->documentId, 'revision_id' => $snapshot->revisionId,
            'revision_no' => $snapshot->revisionNo, 'source_state_seq' => $snapshot->sourceStateSeq,
            'version_digest' => $snapshot->digest, 'text_profile' => RichTextProjection::TEXT_PROFILE,
            'offset_unit' => self::OFFSET_UNIT, 'text' => $text, 'text_sha256' => hash('sha256', $text),
            'text_length' => mb_strlen($text, 'UTF-8'),
        ];
    }

    public static function assertBinding(array $source, array $input): void
    {
        if ($input['text_profile'] !== $source['text_profile']
            || !hash_equals($source['version_digest'], $input['version_digest'])
            || !hash_equals($source['text_sha256'], $input['text_sha256'])) {
            throw BusinessException::conflict('DOCUMENT_COMMENT_SOURCE_CHANGED', '评论的固定版本或文本摘要不一致');
        }
    }

    public static function assertAnchor(array $source, array $input): void
    {
        self::assertBinding($source, $input);
        $start = $input['start_offset'];
        $end = $input['end_offset'];
        if ($start < 0 || $end <= $start || $end > $source['text_length'] || $end - $start > self::MAX_QUOTE_LENGTH) {
            throw BusinessException::invalid('DOCUMENT_COMMENT_RANGE_INVALID', '评论引用区间无效');
        }
        if (mb_substr($source['text'], $start, $end - $start, 'UTF-8') !== $input['quote']) {
            throw BusinessException::conflict('DOCUMENT_COMMENT_QUOTE_MISMATCH', '评论引用必须与固定版本的精确文本一致');
        }
    }
}
