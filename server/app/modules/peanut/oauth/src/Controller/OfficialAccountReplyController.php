<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\OAuth\Controller;

use app\adminapi\controller\BaseAdminController;
use app\common\traits\CrudTrait;
use PeanutAdmin\Modules\OAuth\Service\OfficialAccountReplyApplicationService;
use PeanutAdmin\Modules\OAuth\Validation\OfficialAccountReplyValidate;
use PeanutAdmin\Kernel\Auth\TenantContext;
use think\App;

class OfficialAccountReplyController extends BaseAdminController
{
    use CrudTrait;

    protected const CRUD_VALIDATE = OfficialAccountReplyValidate::class;
    protected const CRUD_NOT_FOUND_MESSAGE = '自动回复不存在';
    protected const CRUD_DELETE_SUCCESS_MESSAGE = '删除成功';
    protected const CRUD_VALIDATE_LISTS = true;
    protected const CRUD_STATUS_FIELD = 'status';

    public function __construct(
        App $app,
        private readonly OfficialAccountReplyApplicationService $replies,
    ) {
        parent::__construct($app);
    }

    protected function resolveCrudContext(): TenantContext
    {
        return $this->tenantAdminContext();
    }

    protected function crudService(): object
    {
        return $this->replies;
    }
}
