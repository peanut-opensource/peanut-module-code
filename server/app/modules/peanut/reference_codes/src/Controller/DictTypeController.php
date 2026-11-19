<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\ReferenceCodes\Controller;

use app\adminapi\controller\BaseAdminController;
use PeanutAdmin\Modules\ReferenceCodes\Service\DictTypeApplicationService;
use PeanutAdmin\Modules\ReferenceCodes\Validation\DictTypeValidate;
use app\common\traits\CrudTrait;
use PeanutAdmin\Kernel\Auth\TenantContext;
use think\response\Json;
use think\App;

class DictTypeController extends BaseAdminController
{
    use CrudTrait;

    protected const CRUD_VALIDATE = DictTypeValidate::class;
    protected const CRUD_NOT_FOUND_MESSAGE = '字典类型不存在';
    protected const CRUD_INPUT_FIELDS = [
        'add' => ['name', 'type', 'is_disable', 'remark'],
        'edit' => ['id', 'name', 'type', 'is_disable', 'remark'],
        'detail' => ['id'],
        'delete' => ['id'],
        'status' => ['id', 'is_disable'],
    ];
    protected const CRUD_WRITABLE_FIELDS = [
        'add' => ['name', 'type', 'is_disable', 'remark'],
        'edit' => ['name', 'type', 'is_disable', 'remark'],
        'status' => ['is_disable'],
    ];

    public function __construct(
        App $app,
        private readonly DictTypeApplicationService $dictionaryTypes,
    ) {
        parent::__construct($app);
    }

    protected function resolveCrudContext(): TenantContext
    {
        return $this->tenantAdminContext();
    }

    protected function crudService(): object
    {
        return $this->dictionaryTypes;
    }

    public function all(): Json
    {
        return $this->data($this->dictionaryTypes->all($this->resolveCrudContext()));
    }
}
