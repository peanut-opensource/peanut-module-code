<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Notification\Controller;

use app\adminapi\controller\BaseAdminController;
use PeanutAdmin\Modules\Notification\Service\NotificationAdminApplicationService;

/** @property-read NotificationAdminApplicationService $notifications 当前 App 中声明式解析的控制器依赖。 */
class NoticeLogController extends BaseAdminController
{
    protected string $notificationsClass = NotificationAdminApplicationService::class;

    public function lists()
    {
        return $this->data($this->notifications->logs(
            $this->tenantAdminContext(),
            $this->tenantAdminActor(),
            $this->request->get(),
        ));
    }

    public function detail()
    {
        $id = (int) $this->request->get('id', 0);
        return $this->data($this->notifications->log(
            $this->tenantAdminContext(),
            $this->tenantAdminActor(),
            $id,
        ));
    }
}
