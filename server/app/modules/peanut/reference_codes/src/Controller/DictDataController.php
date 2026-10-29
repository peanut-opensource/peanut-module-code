<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\ReferenceCodes\Controller;

use app\adminapi\controller\BaseAdminController;
use PeanutAdmin\Modules\ReferenceCodes\Service\DictDataApplicationService;
use PeanutAdmin\Modules\ReferenceCodes\Validation\DictDataValidate;
use app\common\traits\CrudTrait;
use PeanutAdmin\Kernel\Auth\TenantContext;
use think\response\Json;

/** @property-read DictDataApplicationService $dictionaryData 当前 App 中声明式解析的控制器依赖。 */
class DictDataController extends BaseAdminController
{
    use CrudTrait;

    protected string $dictionaryDataClass = DictDataApplicationService::class;
    protected const CRUD_VALIDATE = DictDataValidate::class;
    protected const CRUD_NOT_FOUND_MESSAGE = '字典数据不存在';

    protected function resolveCrudContext(): TenantContext
    {
        return $this->tenantAdminContext();
    }

    protected function crudService(): object
    {
        return $this->dictionaryData;
    }

    /** 按类型标识取启用数据项（业务前端用） */
    public function byType(): Json
    {
        return $this->data($this->dictionaryData->byType(
            $this->resolveCrudContext(),
            (string) $this->request->get('type_value', ''),
        ));
    }
}
