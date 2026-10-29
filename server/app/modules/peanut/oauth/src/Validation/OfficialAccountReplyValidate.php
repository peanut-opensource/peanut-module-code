<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\OAuth\Validation;

use think\Validate;

class OfficialAccountReplyValidate extends Validate
{
    protected $rule = [
        'id' => 'require|integer|gt:0',
        'reply_type' => 'require|in:1,2,3|checkKeywordFields',
        'name' => 'require|max:100|checkNotBlank',
        'keyword' => 'max:255',
        'matching_type' => 'in:1,2',
        'content_type' => 'require|in:1',
        'content' => 'require|max:5000|checkNotBlank',
        'status' => 'require|in:0,1',
        'sort' => 'integer|egt:0',
        'page_no' => 'integer|gt:0',
        'page_size' => 'integer|between:1,100',
    ];

    protected $message = [
        'id.require' => '自动回复不能为空',
        'id.integer' => '自动回复 ID 无效',
        'reply_type.require' => '回复类型不能为空',
        'reply_type.in' => '回复类型无效',
        'name.require' => '规则名称不能为空',
        'name.max' => '规则名称不能超过 100 个字符',
        'keyword.max' => '关键词不能超过 255 个字符',
        'matching_type.in' => '匹配方式无效',
        'content_type.require' => '内容类型不能为空',
        'content_type.in' => '当前仅支持文本回复',
        'content.require' => '回复内容不能为空',
        'content.max' => '回复内容不能超过 5000 个字符',
        'status.require' => '状态不能为空',
        'status.in' => '状态无效',
        'sort.integer' => '排序必须为整数',
        'sort.egt' => '排序不能小于 0',
    ];

    protected $scene = [
        'lists' => ['reply_type' => 'in:1,2,3', 'page_no', 'page_size'],
        'add' => ['reply_type', 'name', 'keyword', 'matching_type', 'content_type', 'content', 'status', 'sort'],
        'edit' => ['id', 'reply_type', 'name', 'keyword', 'matching_type', 'content_type', 'content', 'status', 'sort'],
        'detail' => ['id'],
        'delete' => ['id'],
        'status' => ['id', 'status'],
    ];

    protected function checkNotBlank(mixed $value): bool|string
    {
        return trim((string) $value) !== '' ? true : '内容不能为空';
    }

    protected function checkKeywordFields(mixed $value, mixed $rule, array $data): bool|string
    {
        if ((int) $value !== 2) {
            return true;
        }
        if (trim((string) ($data['keyword'] ?? '')) === '') {
            return '关键词回复必须填写关键词';
        }
        if (!in_array((int) ($data['matching_type'] ?? 0), [1, 2], true)) {
            return '关键词回复必须选择匹配方式';
        }
        if (!isset($data['sort']) || filter_var($data['sort'], FILTER_VALIDATE_INT) === false || (int) $data['sort'] < 0) {
            return '关键词回复排序必须为非负整数';
        }
        return true;
    }
}
