<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Article\Contract;

use app\common\exception\BusinessException;
use PeanutAdmin\Modules\RichText\Contract\RichTextProjection;

/** Explicitly confirmed, full immutable migration input, including the raw source snapshot digest. */
final readonly class ArticleHtmlMigrationInput
{
    public int $articleId;
    public string $requestId;
    public string $ownerRevision;
    public string $sourceSha256;
    public RichTextProjection $projection;

    public function __construct(array $input)
    {
        if (array_diff(array_keys($input), ['id', 'request_id', 'confirmed', 'owner_revision', 'source_sha256', 'structured_content']) !== []
            || ($input['confirmed'] ?? null) !== true
            || !is_string($input['request_id'] ?? null) || preg_match('/^[A-Za-z0-9:_-]{1,128}$/D', $input['request_id']) !== 1
            || !is_string($input['owner_revision'] ?? null) || preg_match('/^[a-f0-9]{64}$/D', $input['owner_revision']) !== 1
            || !is_string($input['source_sha256'] ?? null) || preg_match('/^[a-f0-9]{64}$/D', $input['source_sha256']) !== 1
            || !is_array($input['structured_content'] ?? null)) {
            throw BusinessException::invalid('ARTICLE_HTML_INPUT_INVALID', '须明确确认迁移并提供完整原始预览输入');
        }
        $rawId = $input['id'] ?? null;
        if (!is_int($rawId) && !(is_string($rawId) && preg_match('/^[1-9][0-9]*$/D', $rawId) === 1)) {
            throw BusinessException::invalid('ARTICLE_HTML_INPUT_INVALID', '资讯 ID 无效');
        }
        $id = filter_var($rawId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 9007199254740991]]);
        if ($id === false) {
            throw BusinessException::invalid('ARTICLE_HTML_INPUT_INVALID', '资讯 ID 越界');
        }
        $this->articleId = $id;
        $this->requestId = $input['request_id'];
        $this->ownerRevision = $input['owner_revision'];
        $this->sourceSha256 = $input['source_sha256'];
        $this->projection = RichTextProjection::fromStructured($input['structured_content']);
        // HTML media URLs are not stable authorized File references. This first slice imports text only.
        if ($this->projection->fileReferences !== []) {
            throw BusinessException::invalid('ARTICLE_HTML_MEDIA_UNSUPPORTED', '旧 HTML 媒体不能通过 URL 推导文件引用，请先人工整理');
        }
    }

    public function hash(): string
    {
        return hash('sha256', json_encode([$this->articleId, $this->ownerRevision, $this->sourceSha256, $this->projection->json], JSON_THROW_ON_ERROR));
    }
}
