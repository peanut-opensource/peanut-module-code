<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\ReferenceCodes\Controller;

use app\adminapi\controller\BaseAdminController;
use PeanutAdmin\Modules\ReferenceCodes\Service\DictTypeApplicationService;
use PeanutAdmin\Modules\ReferenceCodes\Validation\DictTypeValidate;
use app\common\traits\CrudTrait;
use PeanutAdmin\Kernel\Auth\TenantContext;
use think\response\Json;

/** @property-read DictTypeApplicationService $dictionaryTypes 当前 App 中声明式解析的控制器依赖。 */
class DictTypeController extends BaseAdminController
{
    use CrudTrait;

    protected string $dictionaryTypesClass = DictTypeApplicationService::class;
    protected const CRUD_VALIDATE = DictTypeValidate::class;
    protected const CRUD_NOT_FOUND_MESSAGE = '字典类型不存在';

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
