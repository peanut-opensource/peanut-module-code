<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\ReferenceCodes\Controller;

use app\adminapi\controller\BaseAdminController;
use PeanutAdmin\Modules\ReferenceCodes\Service\DictDataApplicationService;
use PeanutAdmin\Modules\ReferenceCodes\Validation\DictDataValidate;
use app\common\traits\CrudTrait;
use PeanutAdmin\Kernel\Auth\TenantContext;
use think\response\Json;
use think\App;

class DictDataController extends BaseAdminController
{
    use CrudTrait;

    protected const CRUD_VALIDATE = DictDataValidate::class;
    protected const CRUD_NOT_FOUND_MESSAGE = '字典数据不存在';
    protected const CRUD_INPUT_FIELDS = [
        'add' => ['type_id', 'name', 'value', 'sort', 'is_disable', 'remark'],
        'edit' => ['id', 'name', 'value', 'sort', 'is_disable', 'remark'],
        'detail' => ['id'],
        'delete' => ['id'],
        'status' => ['id', 'is_disable'],
    ];
    protected const CRUD_WRITABLE_FIELDS = [
        'add' => ['type_id', 'name', 'value', 'sort', 'is_disable', 'remark'],
        'edit' => ['name', 'value', 'sort', 'is_disable', 'remark'],
        'status' => ['is_disable'],
    ];

    public function __construct(
        App $app,
        private readonly DictDataApplicationService $dictionaryData,
    ) {
        parent::__construct($app);
    }

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
