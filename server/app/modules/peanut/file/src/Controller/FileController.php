<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\File\Controller;

use app\adminapi\controller\BaseAdminController;
use PeanutAdmin\Modules\File\Contract\FileAdministration;
use PeanutAdmin\Modules\File\Validation\FileCateValidate;
use app\common\exception\BusinessException;

/** @property-read FileAdministration $files 当前 App 中声明式解析的控制器依赖。 */
class FileController extends BaseAdminController
{
    protected string $filesClass = FileAdministration::class;
    protected string $snapshotsClass = \PeanutAdmin\Modules\File\Contract\FileDocumentSnapshots::class;
    public function pendingSnapshots()
    {
        return $this->data($this->snapshots->pendingDocumentSnapshots($this->tenantAdminContext()));
    }
    public function recoverSnapshot()
    {
        $input = $this->request->post();
        if (array_diff(array_keys($input), ['file_key']) !== [] || !is_string($input['file_key'] ?? null) || preg_match('/^file_[0-9a-f]{32}$/D', $input['file_key']) !== 1) {
            throw BusinessException::invalid('FILE_SNAPSHOT_INPUT_INVALID', '恢复资源标识无效');
        }
        return $this->data($this->snapshots->recoverDocumentSnapshot($this->tenantAdminContext(), $input['file_key']));
    }

    public function resolve()
    {
        $input = $this->request->post();
        if (array_diff(array_keys($input), ['file_key', 'operation', 'document_id', 'variant_key', 'viewer_adapter_id']) !== []) {
            throw BusinessException::invalid('FILE_ACCESS_INPUT_INVALID', '资源操作字段无效');
        }
        $fileKey = $input['file_key'] ?? null;
        $operation = $input['operation'] ?? null;
        if (!is_string($fileKey) || !is_string($operation)
            || preg_match('/^file_[0-9a-f]{32}$/D', $fileKey) !== 1
            || !in_array($operation, ['preview', 'download_original', 'manage'], true)) {
            throw BusinessException::invalid('FILE_ACCESS_INPUT_INVALID', '资源操作无效');
        }
        $documentId = isset($input['document_id']) ? $this->integerValue($input['document_id'], '文档 ID 无效') : null;
        $variant = $input['variant_key'] ?? null;
        $viewer = $input['viewer_adapter_id'] ?? null;
        if ($documentId !== null && ($documentId < 1 || $operation !== 'preview')) {
            throw BusinessException::invalid('FILE_ACCESS_INPUT_INVALID', '文档仅可授权资源预览');
        }
        if ($viewer !== null && (!is_string($viewer) || $viewer === '' || strlen($viewer) > 128)) {
            throw BusinessException::invalid('FILE_ACCESS_INPUT_INVALID', '查看工具无效');
        }
        if ($variant !== null && (!is_string($variant) || preg_match('/^[a-z][a-z0-9-]{0,31}$/D', $variant) !== 1)) {
            throw BusinessException::invalid('FILE_ACCESS_INPUT_INVALID', '资源变体无效');
        }
        return $this->data(app(\PeanutAdmin\Modules\File\Contract\FileAccess::class)->resolve(
            $this->executionContext()->tenantAdmin(),
            $fileKey,
            $operation,
            $documentId,
            $variant,
            $viewer,
        ));
    }

    // ---- 文件 ----
    public function lists()
    {
        return $this->data($this->files->lists($this->request->get()));
    }

    public function assets()
    {
        $page = $this->files->imageAssets($this->request->get());
        return json([
            'data' => ['items' => $page->items],
            'meta' => [
                'request_id' => $this->executionContext()->requestId(),
                'page' => $page->page,
                'page_size' => $page->pageSize,
                'total' => $page->total,
            ],
        ]);
    }

    public function move()
    {
        $ids = $this->request->post('ids', []);
        if (!is_array($ids)) {
            throw BusinessException::invalid('FILE_IDS_INVALID', '素材 ID 集合无效');
        }
        $this->files->move(
            $ids,
            $this->integerValue($this->request->post('cid', 0), '目标分类无效'),
        );
        return $this->success('操作成功');
    }

    public function rename()
    {
        $name = trim((string) $this->request->post('name', ''));
        if ($name === '') {
            throw BusinessException::invalid('FILE_NAME_REQUIRED', '名称不能为空');
        }
        if (mb_strlen($name) > 20) {
            throw BusinessException::invalid('FILE_NAME_TOO_LONG', '名称最多 20 个字符');
        }
        $this->files->rename(
            $this->integerValue($this->request->post('id'), '素材 ID 无效'),
            $name,
        );
        return $this->success('操作成功');
    }

    public function delete()
    {
        $ids = $this->request->post('ids', []);
        if (!is_array($ids)) {
            throw BusinessException::invalid('FILE_IDS_INVALID', '素材 ID 集合无效');
        }
        $result = $this->files->delete($ids);
        return $this->success('操作成功', $result);
    }

    // ---- 分类 ----
    public function listCate()
    {
        return $this->data($this->files->categoryLists(
            $this->integerValue($this->request->get('type', 10), '文件类型无效'),
        ));
    }

    public function addCate()
    {
        $this->validate($this->request->post(), FileCateValidate::class . '.add');
        $this->files->addCategory($this->request->post());
        return $this->success('操作成功');
    }

    public function editCate()
    {
        $this->validate($this->request->post(), FileCateValidate::class . '.edit');
        $this->files->editCategory($this->request->post());
        return $this->success('操作成功');
    }

    public function delCate()
    {
        $result = $this->files->deleteCategory(
            $this->integerValue($this->request->post('id'), '分类 ID 无效'),
        );
        return $this->success('操作成功', $result);
    }

    private function integerValue(mixed $value, string $message): int
    {
        if (!is_int($value) && !(is_string($value) && preg_match('/^-?\d+$/D', $value) === 1)) {
            throw new \InvalidArgumentException($message);
        }
        return (int) $value;
    }
}
