<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Notification\Controller;

use app\adminapi\controller\BaseAdminController;
use PeanutAdmin\Modules\Notification\Service\NotificationAdminApplicationService;

/**
 * 通知渠道配置控制器
 * @property-read NotificationAdminApplicationService $notifications 当前 App 中声明式解析的控制器依赖。
 */
class NoticeChannelController extends BaseAdminController
{
    protected string $notificationsClass = NotificationAdminApplicationService::class;

    /**
     * 获取渠道配置（脱敏：密钥只返回是否已设置）
     */
    public function detail(): \think\Response
    {
        return $this->data($this->notifications->channel(
            $this->tenantAdminContext(),
            $this->tenantAdminActor(),
        ));
    }

    /**
     * 保存渠道配置
     * 前端分块提交：{ section: 'sms_aliyun'|'sms_tencent'|'sms_default', ...fields }
     */
    public function save(): \think\Response
    {
        $post    = $this->request->post();
        $section = (string) ($post['section'] ?? '');

        unset($post['section']);
        $this->notifications->saveChannel(
            $this->tenantAdminContext(),
            $this->tenantAdminActor(),
            $section,
            $post,
        );
        return $this->success('保存成功');
    }
}
