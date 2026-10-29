<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Notification\Validation;

use PeanutAdmin\Modules\Notification\Contract\NotificationQueries;
use think\Validate;

class NoticeSceneValidate extends Validate
{
    public function __construct(private readonly NotificationQueries $queries)
    {
        parent::__construct();
    }

    protected $rule = [
        'id'              => 'require|integer|gt:0|checkScene',
        'sms_template_id' => 'max:100|checkTemplateId',
        'sms_content'     => 'max:500|checkContent',
        'sms_status'      => 'require|in:0,1',
    ];

    protected $message = [
        'id.require'             => '通知场景不能为空',
        'sms_template_id.max'    => '服务商模板 ID 最多 100 个字符',
        'sms_content.max'        => '短信内容最多 500 个字符',
        'sms_status.require'     => '短信通知状态不能为空',
        'sms_status.in'          => '短信通知状态无效',
    ];

    protected $scene = [
        'detail' => ['id'],
        'save'   => ['id', 'sms_template_id', 'sms_content', 'sms_status'],
    ];

    protected function checkScene($value): bool|string
    {
        return $this->queries->sceneExists((int) $value)
            ? true
            : '通知场景不存在';
    }

    protected function checkTemplateId($value, $rule, array $data): bool|string
    {
        if ((int) ($data['sms_status'] ?? 0) === 1 && trim((string) $value) === '') {
            return '启用短信通知前请填写服务商模板 ID';
        }
        return true;
    }

    protected function checkContent($value, $rule, array $data): bool|string
    {
        if ((int) ($data['sms_status'] ?? 0) !== 1) {
            return true;
        }
        $content = trim((string) $value);
        if ($content === '') {
            return '启用短信通知前请填写短信内容';
        }
        return str_contains($content, '${code}')
            ? true
            : '短信内容必须包含 ${code} 变量';
    }

}
