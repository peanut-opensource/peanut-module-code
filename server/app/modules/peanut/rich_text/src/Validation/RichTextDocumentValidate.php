<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\RichText\Validation;

use app\common\validate\PageSizeRule;
use app\common\validate\TenantContextValidate;
use PeanutAdmin\Modules\RichText\Model\RichTextDocument;

final class RichTextDocumentValidate extends TenantContextValidate
{
    use PageSizeRule;

    protected $rule = [
        'id' => 'require|integer|gt:0|checkExists',
        'title' => 'require|length:1,200',
        'document' => 'require|array|checkDocumentPayload',
        'collaboration_state' => 'checkCollaborationState',
        'revision' => 'require|integer|gt:0',
        'page_no' => 'integer|gt:0',
        'page_size' => 'integer|gt:0|pageSizeMax',
    ];

    protected $message = [
        'id.require' => '文档 ID 不能为空',
        'title.require' => '文档标题不能为空',
        'title.length' => '文档标题长度须在 1-200 个字符之间',
        'document.require' => '文档内容不能为空',
        'revision.require' => '文档版本不能为空',
    ];

    protected $scene = [
        'lists' => ['title', 'page_no', 'page_size'],
        'detail' => ['id'],
        'add' => ['title', 'document', 'collaboration_state'],
        'edit' => ['id', 'title', 'document', 'collaboration_state', 'revision'],
        'delete' => ['id'],
        'collaboration' => ['id'],
    ];

    public function sceneLists(): self
    {
        return $this->only($this->scene['lists'])->remove('title', 'require');
    }

    protected function checkExists(mixed $value): bool|string
    {
        $this->requireTenantContext();
        return RichTextDocument::where('id', (int) $value)->findOrEmpty()->isEmpty()
            ? '文档不存在'
            : true;
    }

    protected function checkDocumentPayload(mixed $value): bool|string
    {
        if (!is_array($value)
            || ($value['schemaVersion'] ?? null) !== 'peanut.richtext/1'
            || ($value['editorModel'] ?? null) !== 'tiptap-prosemirror'
            || !is_array($value['content'] ?? null)
            || ($value['content']['type'] ?? null) !== 'doc'
            || !is_array($value['annotations'] ?? null)
            || !array_is_list($value['annotations'])) {
            return '文档格式无效';
        }
        try {
            $encoded = json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES, 64);
        } catch (\JsonException) {
            return '文档格式无效';
        }
        return strlen($encoded) <= 1024 * 1024 ? true : '文档内容不能超过 1 MiB';
    }

    protected function checkCollaborationState(mixed $value): bool|string
    {
        if ($value === null || $value === '') {
            return true;
        }
        if (!is_string($value)) {
            return '协同状态格式无效';
        }
        $decoded = base64_decode($value, true);
        return is_string($decoded) && strlen($decoded) <= 8 * 1024 * 1024
            ? true
            : '协同状态格式无效或超过 8 MiB';
    }
}
