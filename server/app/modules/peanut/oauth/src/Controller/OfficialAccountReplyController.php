<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\OAuth\Controller;

use app\adminapi\controller\BaseAdminController;
use app\common\traits\CrudTrait;
use PeanutAdmin\Modules\OAuth\Service\OfficialAccountReplyApplicationService;
use PeanutAdmin\Modules\OAuth\Validation\OfficialAccountReplyValidate;
use PeanutAdmin\Kernel\Auth\TenantContext;

/** @property-read OfficialAccountReplyApplicationService $replies 当前 App 中声明式解析的控制器依赖。 */
class OfficialAccountReplyController extends BaseAdminController
{
    use CrudTrait;

    protected const CRUD_VALIDATE = OfficialAccountReplyValidate::class;
    protected const CRUD_NOT_FOUND_MESSAGE = '自动回复不存在';
    protected const CRUD_DELETE_SUCCESS_MESSAGE = '删除成功';
    protected const CRUD_VALIDATE_LISTS = true;
    protected const CRUD_STATUS_FIELD = 'status';

    protected string $repliesClass = OfficialAccountReplyApplicationService::class;

    protected function resolveCrudContext(): TenantContext
    {
        return $this->tenantAdminContext();
    }

    protected function crudService(): object
    {
        return $this->replies;
    }
}
