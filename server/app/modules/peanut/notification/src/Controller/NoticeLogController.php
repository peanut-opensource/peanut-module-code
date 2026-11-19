<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Notification\Controller;

use app\adminapi\controller\BaseAdminController;
use PeanutAdmin\Modules\Notification\Service\NotificationAdminApplicationService;
use think\App;

class NoticeLogController extends BaseAdminController
{
    public function __construct(
        App $app,
        private readonly NotificationAdminApplicationService $notifications,
    ) {
        parent::__construct($app);
    }

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
