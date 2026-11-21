<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Task\Controller;

use app\adminapi\controller\BaseAdminController;
use app\common\http\PageResult;
use app\common\traits\CrudTrait;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Modules\Task\Service\TaskAdminApplicationService;
use PeanutAdmin\Modules\Task\Validation\CrontabValidate;
use think\App;

class CrontabController extends BaseAdminController
{
    public function __construct(
        App $app,
        private readonly TaskAdminApplicationService $crontabs,
    ) {
        parent::__construct($app);
    }

    use CrudTrait;

    protected const CRUD_VALIDATE = CrontabValidate::class;
    protected const CRUD_ADD_SUCCESS_MESSAGE = '添加成功';
    protected const CRUD_EDIT_SUCCESS_MESSAGE = '编辑成功';
    protected const CRUD_DELETE_SUCCESS_MESSAGE = '删除成功';

    protected function resolveCrudContext(): TenantContext
    {
        return $this->tenantAdminContext();
    }

    protected function validatedInput(mixed $context, string $scene, array $params): array
    {
        $this->validate($params, CrontabValidate::class . '.' . $scene);
        return $params;
    }

    protected function performLists(mixed $context, array $params): PageResult
    {
        return $this->crontabs->crontabs($context, $this->tenantAdminActor(), $params);
    }

    protected function performDetail(mixed $context, array $params): array
    {
        return $this->crontabs->crontab($context, $this->tenantAdminActor(), (int) $params['id']);
    }

    protected function performAdd(mixed $context, array $params): bool
    {
        return $this->crontabs->addCrontab($context, $this->tenantAdminActor(), $params);
    }

    protected function performEdit(mixed $context, array $params): bool
    {
        return $this->crontabs->editCrontab($context, $this->tenantAdminActor(), $params);
    }

    protected function performDelete(mixed $context, array $params): bool
    {
        return $this->crontabs->deleteCrontab($context, $this->tenantAdminActor(), (int) $params['id']);
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
