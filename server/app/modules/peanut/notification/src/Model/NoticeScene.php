<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Notification\Model;

use app\common\model\TenantOwnedModel;

/**
 * 通知场景模型。
 */
class NoticeScene extends TenantOwnedModel
{
    protected $name = 'notice_scene';

    public const STATUS_DISABLED = 0;
    public const STATUS_ENABLED = 1;

    /** @param list<array{0:string,1:string,2:string,3:string}> $scenes */
    public static function provisionDefaults(int $tenantId, array $scenes): void
    {
        $existing = array_fill_keys(array_map(
            'strval',
            self::withoutTenantScope()->where('tenant_id', $tenantId)
                ->whereIn('code', array_column($scenes, 0))->column('code'),
        ), true);
        $missing = [];
        foreach ($scenes as [$code, $name, $description, $content]) {
            if (isset($existing[$code])) {
                continue;
            }
            $missing[] = [
                'tenant_id' => $tenantId,
                'code' => $code,
                'name' => $name,
                'description' => $description,
                'recipient' => '用户',
                'variables' => json_encode(['code'], JSON_THROW_ON_ERROR),
                'sms_template_id' => '',
                'sms_content' => $content,
                'sms_status' => self::STATUS_DISABLED,
                'create_time' => 0,
                'update_time' => 0,
            ];
        }
        if ($missing !== []) {
            self::withoutTenantScope()->insertAll($missing);
        }
    }
}
