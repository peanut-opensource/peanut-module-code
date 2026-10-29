<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Article\Controller;

use app\adminapi\controller\BaseAdminController;
use app\common\traits\CrudTrait;
use PeanutAdmin\Modules\Article\Contract\ArticleCategoryAdministration;
use PeanutAdmin\Modules\Article\Validation\ArticleCateValidate;
use PeanutAdmin\Kernel\Auth\TenantContext;
use think\response\Json;

/** @property-read ArticleCategoryAdministration $crud 当前 App 中正式绑定的资讯分类管理用例。 */
class ArticleCateController extends BaseAdminController
{
    use CrudTrait;

    protected string $crudClass = ArticleCategoryAdministration::class;
    protected const CRUD_VALIDATE = ArticleCateValidate::class;
    protected const CRUD_ADD_SUCCESS_MESSAGE = '添加成功';
    protected const CRUD_EDIT_SUCCESS_MESSAGE = '编辑成功';
    protected const CRUD_DELETE_SUCCESS_MESSAGE = '删除成功';
    protected const CRUD_STATUS_SUCCESS_MESSAGE = '修改成功';
    protected const CRUD_VALIDATE_LISTS = true;
    protected const CRUD_STATUS_FIELD = 'is_show';
    protected const CRUD_SOFT_DELETE = true;
    protected const CRUD_INPUT_FIELDS = [
        'lists' => [
            'page_no', 'page_size', 'page_start', 'page_end', 'page_type', 'order_by',
            'field', 'name', 'is_show', 'start_time', 'end_time', 'start', 'end', 'export', 'file_name',
        ],
        'detail' => ['id'],
        'add' => ['name', 'is_show', 'sort'],
        'edit' => ['id', 'name', 'is_show', 'sort'],
        'delete' => ['id'],
        'status' => ['id', 'is_show'],
        'recycle' => [
            'page_no', 'page_size', 'page_start', 'page_end', 'page_type', 'order_by',
            'field', 'name', 'is_show', 'start_time', 'end_time', 'start', 'end', 'export', 'file_name',
        ],
        'recycleDetail' => ['id'],
        'restore' => ['id', 'ids'],
        'forceDelete' => ['id', 'ids'],
    ];
    protected const CRUD_WRITABLE_FIELDS = [
        'add' => ['name', 'is_show', 'sort'],
        'edit' => ['name', 'is_show', 'sort'],
        'status' => ['is_show'],
    ];

    protected function resolveCrudContext(): TenantContext
    {
        return $this->tenantAdminContext();
    }

    public function all(): Json
    {
        $context = $this->resolveCrudContext();
        return $this->data($this->crud->all($context));
    }

    protected function renderDetail(array $result): Json
    {
        return $this->data($result);
    }

}
