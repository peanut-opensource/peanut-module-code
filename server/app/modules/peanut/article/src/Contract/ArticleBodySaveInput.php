<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Article\Contract;

use app\common\exception\BusinessException;

/** Exact existing-owner Save input. Body bytes remain exclusively in Document. */
final readonly class ArticleBodySaveInput
{
    public array $metadata;
    public int $articleId;
    public int $documentId;
    public int $expectedStateSeq;
    public string $ownerRevision;
    public string $requestId;

    public function __construct(array $input)
    {
        $fields = ['id','document_id','expected_state_seq','owner_revision','request_id','cid','title','desc','abstract','image','author','click_virtual','is_show','sort'];
        if (array_diff(array_keys($input), $fields) !== [] || !is_string($input['request_id'] ?? null)
            || preg_match('/^[A-Za-z0-9:_-]{1,128}$/D', $input['request_id']) !== 1
            || !is_string($input['owner_revision'] ?? null) || preg_match('/^[a-f0-9]{64}$/D', $input['owner_revision']) !== 1) {
            throw BusinessException::invalid('ARTICLE_BODY_SAVE_INPUT_INVALID', '正文保存输入无效');
        }
        $this->articleId = self::integer($input['id'] ?? null, 1);
        $this->documentId = self::integer($input['document_id'] ?? null, 1);
        $this->expectedStateSeq = self::integer($input['expected_state_seq'] ?? null, 0);
        $this->requestId = $input['request_id'];
        $this->ownerRevision = $input['owner_revision'];
        $metadata = ['cid' => self::integer($input['cid'] ?? null, 1)];
        foreach (['title' => 255,'desc' => 255,'abstract' => 10000,'image' => 2048,'author' => 255] as $key => $maximum) {
            $value = $input[$key] ?? ($key === 'title' ? null : '');
            if (!is_string($value) || mb_strlen($value) > $maximum || $key === 'title' && trim($value) === '') {
                throw BusinessException::invalid('ARTICLE_BODY_SAVE_INPUT_INVALID', '资讯元数据无效');
            }
            $metadata[$key] = $value;
        }
        $metadata['click_virtual'] = self::integer($input['click_virtual'] ?? 0, 0);
        $metadata['is_show'] = self::integer($input['is_show'] ?? null, 0);
        $metadata['sort'] = self::integer($input['sort'] ?? 0, 0);
        if ($metadata['is_show'] > 1) {
            throw BusinessException::invalid('ARTICLE_BODY_SAVE_INPUT_INVALID', '显示状态无效');
        }
        if ($metadata['is_show'] !== 0) {
            throw BusinessException::conflict('ARTICLE_PUBLIC_FIXED_VERSION_UNAVAILABLE', '固定正文公开解析尚未接入，请先保存为未公开资讯');
        }
        $this->metadata = $metadata;
    }

    public function hash(): string
    {
        return hash('sha256', json_encode([$this->articleId,$this->documentId,$this->expectedStateSeq,$this->ownerRevision,$this->metadata], JSON_THROW_ON_ERROR));
    }

    private static function integer(mixed $value, int $minimum): int
    {
        if (!is_int($value) && !(is_string($value) && preg_match('/^(0|[1-9][0-9]*)$/D', $value) === 1)) {
            throw BusinessException::invalid('ARTICLE_BODY_SAVE_INPUT_INVALID', '保存整数无效');
        }
        $number = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => $minimum,'max_range' => 9007199254740991]]);
        if ($number === false) {
            throw BusinessException::invalid('ARTICLE_BODY_SAVE_INPUT_INVALID', '保存整数越界');
        }
        return $number;
    }
}
