<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Task\Controller;

use app\adminapi\controller\BaseAdminController;
use PeanutAdmin\Modules\Task\Service\TaskAdminApplicationService;
use PeanutAdmin\Modules\Task\Validation\CrontabValidate;

/**
 * 定时任务控制器
 * @property-read TaskAdminApplicationService $crontabs 当前 App 中声明式解析的控制器依赖。
 */
class CrontabController extends BaseAdminController
{
    protected string $crontabsClass = TaskAdminApplicationService::class;

    public function lists()
    {
        $res = $this->crontabs->crontabs(
            $this->tenantAdminContext(),
            $this->tenantAdminActor(),
            $this->request->get(),
        );
        return $this->data($res);
    }

    public function detail()
    {
        $params = $this->request->get();
        $this->validate($params, CrontabValidate::class . '.detail');
        $result = $this->crontabs->crontab(
            $this->tenantAdminContext(),
            $this->tenantAdminActor(),
            (int) $params['id'],
        );
        return $this->data($result);
    }

    public function add()
    {
        $this->validate($this->request->post(), CrontabValidate::class . '.add');
        $this->crontabs->addCrontab(
            $this->tenantAdminContext(),
            $this->tenantAdminActor(),
            $this->request->post(),
        );
        return $this->success('添加成功');
    }

    public function edit()
    {
        $this->validate($this->request->post(), CrontabValidate::class . '.edit');
        $this->crontabs->editCrontab(
            $this->tenantAdminContext(),
            $this->tenantAdminActor(),
            $this->request->post(),
        );
        return $this->success('编辑成功');
    }

    public function delete()
    {
        $params = $this->request->post();
        $this->validate($params, CrontabValidate::class . '.delete');
        $this->crontabs->deleteCrontab(
            $this->tenantAdminContext(),
            $this->tenantAdminActor(),
            (int) $params['id'],
        );
        return $this->success('删除成功');
    }

    public function operate()
    {
        $params = $this->request->post();
        $this->validate($params, CrontabValidate::class . '.operate');
        $id      = (int) $params['id'];
        $operate = (string) $params['operate'];
        $this->crontabs->operateCrontab(
            $this->tenantAdminContext(),
            $this->tenantAdminActor(),
            $id,
            $operate,
        );
        return $this->success('操作成功');
    }

    public function expression()
    {
        $params = $this->request->get();
        $this->validate($params, CrontabValidate::class . '.expression');
        $expression = (string) $params['expression'];
        return $this->data($this->crontabs->previewExpression(
            $this->tenantAdminContext(),
            $this->tenantAdminActor(),
            $expression,
        ));
    }
}
