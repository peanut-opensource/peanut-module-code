<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Notification\Controller;

use app\adminapi\controller\BaseAdminController;
use PeanutAdmin\Modules\Notification\Service\NotificationAdminApplicationService;
use PeanutAdmin\Modules\Notification\Validation\NoticeSceneValidate;

/** @property-read NotificationAdminApplicationService $notifications 当前 App 中声明式解析的控制器依赖。 */
class NoticeSceneController extends BaseAdminController
{
    protected string $notificationsClass = NotificationAdminApplicationService::class;

    public function lists()
    {
        return $this->data($this->notifications->scenes(
            $this->tenantAdminContext(),
            $this->tenantAdminActor(),
        ));
    }

    public function detail()
    {
        $params = $this->request->get();
        $this->validate($params, NoticeSceneValidate::class . '.detail');
        return $this->data($this->notifications->scene(
            $this->tenantAdminContext(),
            $this->tenantAdminActor(),
            (int) $params['id'],
        ));
    }

    public function save()
    {
        $params = $this->request->post();
        $this->validate($params, NoticeSceneValidate::class . '.save');
        $this->notifications->saveScene(
            $this->tenantAdminContext(),
            $this->tenantAdminActor(),
            $params,
        );
        return $this->success('保存成功');
    }

}
