<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Article\Controller;

use app\adminapi\controller\BaseAdminController;
use app\common\traits\CrudTrait;
use PeanutAdmin\Modules\Article\Contract\ArticleAdministration;
use PeanutAdmin\Modules\Article\Validation\ArticleValidate;
use PeanutAdmin\Kernel\Auth\TenantContext;
use think\response\Json;

/** @property-read ArticleAdministration $crud 当前 App 中正式绑定的资讯管理用例。 */
class ArticleController extends BaseAdminController
{
    use CrudTrait;

    protected string $crudClass = ArticleAdministration::class;
    public function bodyEditor(): Json
    {
        $input = $this->bodyInput($this->request->get(), false);
        return $this->data($this->crud->bodyEditor($this->tenantAdminContext(), $input['id']));
    }

    public function bodyTransport(): Json
    {
        $input = $this->bodyInput($this->request->post(), false);
        return $this->data($this->crud->bodyTransport($this->tenantAdminContext(), $input['id']));
    }

    public function bodySave(): Json
    {
        return $this->data($this->crud->bodySave($this->tenantAdminContext(), $this->request->post()));
    }

    public function bodySaveOperation(): Json
    {
        $input = $this->bodyInput($this->request->get(), true);
        return $this->data($this->crud->bodySaveOperation($this->tenantAdminContext(), $input['id'], $input['request_id']));
    }

    private function bodyInput(array $input, bool $requestId): array
    {
        if (array_diff(array_keys($input), $requestId ? ['id', 'request_id'] : ['id']) !== []
            || $requestId && (!is_string($input['request_id'] ?? null)
                || preg_match('/^[A-Za-z0-9:_-]{1,128}$/D', $input['request_id']) !== 1)) {
            throw \app\common\exception\BusinessException::invalid('ARTICLE_DOCUMENT_INPUT_INVALID', '资讯正文输入无效');
        }
        $raw = $input['id'] ?? null;
        if (!is_int($raw) && !(is_string($raw) && preg_match('/^[1-9][0-9]*$/D', $raw) === 1)) {
            throw \app\common\exception\BusinessException::invalid('ARTICLE_DOCUMENT_INPUT_INVALID', '资讯 ID 无效');
        }
        $id = filter_var($raw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 9007199254740991]]);
        if ($id === false) {
            throw \app\common\exception\BusinessException::invalid('ARTICLE_DOCUMENT_INPUT_INVALID', '资讯 ID 无效');
        }
        return ['id' => $id] + ($requestId ? ['request_id' => $input['request_id']] : []);
    }

    public function bodyDocument(): Json
    {
        $input = $this->request->post();
        if (array_diff(array_keys($input), ['id','request_id']) !== [] || !is_string($input['request_id'] ?? null)) {
            throw \app\common\exception\BusinessException::invalid('ARTICLE_DOCUMENT_INPUT_INVALID', '资讯文档输入无效');
        }
        $raw = $input['id'] ?? null;
        if (!is_int($raw) && !(is_string($raw) && preg_match('/^[1-9][0-9]*$/D', $raw) === 1)) {
            throw \app\common\exception\BusinessException::invalid('ARTICLE_DOCUMENT_INPUT_INVALID', '资讯 ID 无效');
        }
        $id = filter_var($raw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) {
            throw \app\common\exception\BusinessException::invalid('ARTICLE_DOCUMENT_INPUT_INVALID', '资讯 ID 无效');
        }
        return $this->data($this->crud->bodyDocument($this->tenantAdminContext(), $id, $input['request_id']));
    }
    protected const CRUD_VALIDATE = ArticleValidate::class;
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
            'field', 'title', 'cid', 'is_show', 'start_time', 'end_time', 'start', 'end', 'export', 'file_name',
        ],
        'detail' => ['id'],
        'add' => ['title', 'cid', 'desc', 'abstract', 'image', 'author', 'content', 'click_virtual', 'is_show', 'sort'],
        'edit' => ['id', 'title', 'cid', 'desc', 'abstract', 'image', 'author', 'content', 'click_virtual', 'is_show', 'sort'],
        'delete' => ['id'],
        'status' => ['id', 'is_show'],
        'recycle' => [
            'page_no', 'page_size', 'page_start', 'page_end', 'page_type', 'order_by',
            'field', 'title', 'cid', 'is_show', 'start_time', 'end_time', 'start', 'end', 'export', 'file_name',
        ],
        'recycleDetail' => ['id'],
        'restore' => ['id', 'ids'],
        'forceDelete' => ['id', 'ids'],
    ];
    protected const CRUD_WRITABLE_FIELDS = [
        'add' => ['title', 'cid', 'desc', 'abstract', 'image', 'author', 'content', 'click_virtual', 'is_show', 'sort'],
        'edit' => ['title', 'cid', 'desc', 'abstract', 'image', 'author', 'content', 'click_virtual', 'is_show', 'sort'],
        'status' => ['is_show'],
    ];

    protected function resolveCrudContext(): TenantContext
    {
        return $this->tenantAdminContext();
    }

    protected function renderDetail(array $result): Json
    {
        return $this->data($result);
    }
}
