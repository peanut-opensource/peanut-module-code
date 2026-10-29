<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\RichText\Controller;

use app\adminapi\controller\BaseAdminController;
use app\common\http\PageResult;
use app\common\traits\CrudTrait;
use PeanutAdmin\Modules\RichText\Service\RichTextDocumentService;
use PeanutAdmin\Modules\RichText\Validation\RichTextDocumentValidate;
use PeanutAdmin\Kernel\Auth\TenantContext;
use think\response\Json;

/** @property-read RichTextDocumentService $documents 当前 App 中声明式解析的控制器依赖。 */
final class RichTextDocumentController extends BaseAdminController
{
    use CrudTrait;

    protected const CRUD_VALIDATE = RichTextDocumentValidate::class;
    protected const CRUD_VALIDATE_LISTS = true;
    protected const CRUD_ADD_SUCCESS_MESSAGE = '文档已创建';
    protected const CRUD_EDIT_SUCCESS_MESSAGE = '文档已保存';
    protected const CRUD_DELETE_SUCCESS_MESSAGE = '文档已删除';

    protected string $documentsClass = RichTextDocumentService::class;

    public function collaboration(): Json
    {
        $params = $this->validatedInput(
            $this->tenantAdminContext(),
            'collaboration',
            $this->request->get(),
        );
        return $this->data($this->documents->collaboration((int) $params['id']));
    }

    protected function resolveCrudContext(): TenantContext
    {
        return $this->tenantAdminContext();
    }

    protected function crudService(): object
    {
        return $this->documents;
    }

    protected function performLists(mixed $_context, array $params): PageResult
    {
        return $this->documents->lists($params);
    }

    protected function performDetail(mixed $_context, array $params): array
    {
        return $this->documents->detail((int) $params['id']);
    }

    protected function performAdd(mixed $_context, array $params): bool
    {
        return $this->documents->add($params);
    }

    protected function performEdit(mixed $_context, array $params): bool
    {
        return $this->documents->edit($params);
    }

    protected function performDelete(mixed $_context, array $params): bool
    {
        return $this->documents->delete((int) $params['id']);
    }
}
