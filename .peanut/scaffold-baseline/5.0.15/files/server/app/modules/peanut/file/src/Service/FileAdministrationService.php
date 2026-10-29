<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\File\Service;

use PeanutAdmin\Modules\File\Model\File;
use PeanutAdmin\Modules\File\Model\FileCate;
use PeanutAdmin\Modules\File\Model\Storage\FileDerivative;
use PeanutAdmin\Modules\File\Model\Storage\FileImageAsset;
use PeanutAdmin\Modules\File\Model\Storage\FileObject;
use PeanutAdmin\Modules\File\Contract\FileAdministration;
use app\common\enum\FileEnum;
use app\common\execution\CurrentExecutionContext;
use app\common\http\PageResult;
use PeanutAdmin\Modules\File\Service\Storage\StorageService;
use app\common\support\PaginationInput;
use app\common\support\PositiveIds;
use think\facade\Db;

/** Application use cases for File media and categories. */
final class FileAdministrationService implements FileAdministration
{
    public function __construct(
        private readonly StorageService $storage,
        private readonly CurrentExecutionContext $executionContext,
        private readonly FileService $files,
        private readonly FileAuthorizationService $authorization,
    ) {}

    /** 分页列表：按 type / 分类子树 / source / name 组合过滤，追加 url。 */
    public function lists(array $params): PageResult
    {
        $type = $this->integerValue($params['type'] ?? null, '文件类型无效');
        if (!FileEnum::isValidType($type)) {
            throw new \InvalidArgumentException('文件类型无效');
        }

        $where = [['type', '=', $type]];
        $categoryIds = null;
        if (array_key_exists('cid', $params) && $params['cid'] !== '') {
            $cid = $this->integerValue($params['cid'], '文件分类无效');
            if ($cid < 0) {
                throw new \InvalidArgumentException('文件分类无效');
            }
            $categoryIds = $cid === 0 ? [0] : $this->subtreeIds($cid, $type);
        }
        if (array_key_exists('source', $params) && $params['source'] !== '') {
            $source = $this->integerValue($params['source'], '上传来源无效');
            if (!in_array($source, [FileEnum::SOURCE_ADMIN, FileEnum::SOURCE_USER], true)) {
                throw new \InvalidArgumentException('上传来源无效');
            }
            $where[] = ['source', '=', $source];
        }
        if (!empty($params['name'])) {
            $where[] = ['name', 'like', '%' . trim((string) $params['name']) . '%'];
        }

        $pagination = PaginationInput::from($params);
        $query = File::where([])->where($where);
        if ($categoryIds !== null) {
            $query->whereIn('cid', $categoryIds);
        }

        $pageResult = $pagination->result($query->order(['id' => 'desc']));
        $pageResult = $pageResult->map(static fn(mixed $item): array => $item instanceof \think\Model
            ? $item->toArray()
            : (array) $item);
        $lists = $pageResult->items;
        $objects = [];
        $fileKeys = array_column($lists, 'file_key');
        if ($fileKeys !== []) {
            foreach (FileObject::where([])->whereIn('file_key', $fileKeys)->field('file_key,media_type,sha256,status,access_type,revision')->select()->toArray() as $object) {
                $objects[(string) $object['file_key']] = $object;
            }
        }
        foreach ($lists as &$item) {
            $object = $objects[(string) $item['file_key']] ?? null;
            $item['visibility_state'] = $object['status'] ?? null;
            $item['access_type'] = $object['access_type'] ?? null;
            $item['file_revision'] = isset($object['revision']) ? (int) $object['revision'] : null;
            if (in_array($object['status'] ?? null, ['restricting','verifying','restricted_attention'], true)) {
                $item['url'] = '';
                $item['preview_state'] = 'preview_unavailable';
                $item['can_download_original'] = false;
                $item['verified_mime_type'] = $object['media_type'];
                $item['source_sha256'] = $object['sha256'];
                $item['capabilities'] = null;
                $item['preview_unavailable_reason'] = 'FILE_VISIBILITY_RESTRICTED';
                $item['authorization_revision'] = null;
                continue;
            }
            $viewer = match ($type) {
                FileEnum::IMAGE => 'builtin.image', FileEnum::VIDEO => 'builtin.video', default => null
            };
            $preview = $this->storage->resolveAuthorized($this->executionContext->tenantAdmin(), (string) $item['file_key'], 'preview', null, null, $viewer);
            $item['url'] = $preview['url'] ?? '';
            $item['preview_state'] = $preview['state'];
            $item['can_download_original'] = $preview['can_download_original'];
            $object = $objects[(string) $item['file_key']] ?? null;
            $item['verified_mime_type'] = $object['media_type'] ?? null;
            $item['source_sha256'] = $object['sha256'] ?? null;
            $item['capabilities'] = $preview['capabilities'] ?? null;
            $item['preview_unavailable_reason'] = $preview['capabilities']['unavailableReason'] ?? ($preview['state'] === 'preview_unavailable' ? 'FILE_PREVIEW_UNAVAILABLE' : null);
            $item['authorization_revision'] = $preview['authorization_revision'] ?? null;
        }
        unset($item);

        return new PageResult($lists, $pageResult->total, $pageResult->page, $pageResult->pageSize);
    }

    /** Canonical selector projection. Every variant is another object in the same file_key ledger. */
    public function imageAssets(array $params): PageResult
    {
        $page = $this->lists([...$params, 'type' => FileEnum::IMAGE]);
        $fileKeys = array_values(array_filter(array_map(
            static fn(array $item): string => (string) ($item['file_key'] ?? ''),
            $page->items,
        )));
        if ($fileKeys === []) {
            return $page;
        }

        $objects = [];
        foreach (FileObject::where([])->whereIn('file_key', $fileKeys)->select()->toArray() as $object) {
            $objects[(string) $object['file_key']] = $object;
        }
        $metadata = [];
        foreach (FileImageAsset::where([])->whereIn('file_key', $fileKeys)->select()->toArray() as $item) {
            $metadata[(string) $item['file_key']] = $item;
        }
        $derivatives = [];
        $derivativeKeys = [];
        foreach (FileDerivative::where([])->whereIn('source_file_key', $fileKeys)->order(['variant_key' => 'asc'])->select()->toArray() as $item) {
            $derivatives[(string) $item['source_file_key']][] = $item;
            $derivativeKeys[] = (string) $item['derivative_file_key'];
        }
        $derivativeObjects = [];
        if ($derivativeKeys !== []) {
            foreach (FileObject::where([])->whereIn('file_key', array_values(array_unique($derivativeKeys)))->select()->toArray() as $object) {
                $derivativeObjects[(string) $object['file_key']] = $object;
            }
        }

        return $page->map(function (array $item) use ($objects, $metadata, $derivatives, $derivativeObjects): array {
            $fileKey = (string) $item['file_key'];
            $object = $objects[$fileKey] ?? [];
            $image = $metadata[$fileKey] ?? null;
            $variants = [];
            foreach ($derivatives[$fileKey] ?? [] as $derivative) {
                $derivativeKey = (string) $derivative['derivative_file_key'];
                $derivativeObject = $derivativeObjects[$derivativeKey] ?? null;
                if (!is_array($derivativeObject) || ($derivativeObject['status'] ?? null) !== 'ready') {
                    continue;
                }
                $variants[] = [
                    'variant_key' => (string) $derivative['variant_key'],
                    'file_key' => $derivativeKey,
                    'width' => (int) $derivative['width'],
                    'height' => (int) $derivative['height'],
                    'media_type' => (string) $derivative['media_type'],
                    'delivery_uri' => $this->storage->resolveAuthorized($this->executionContext->tenantAdmin(), $fileKey, 'preview', null, (string) $derivative['variant_key'], 'builtin.image')['url'] ?? '',
                ];
            }
            return [
                'id' => (int) $item['id'],
                'file_key' => $fileKey,
                'original_name' => (string) ($object['original_name'] ?? $item['name']),
                'media_type' => (string) ($object['media_type'] ?? 'application/octet-stream'),
                'width' => is_array($image) ? (int) $image['width'] : null,
                'height' => is_array($image) ? (int) $image['height'] : null,
                'preview_uri' => (string) $item['url'],
                'variants' => $variants,
            ];
        });
    }

    /** 批量移动到分类。 */
    public function move(array $ids, int $cid): void
    {
        $this->authorization->requireManagementPermission($this->executionContext->tenantAdmin(), 'peanut.file.move');
        $ids = $this->normalizeIds($ids);
        $rows = File::where([])->whereIn('id', $ids)->select()->toArray();
        if (count($rows) !== count($ids)) {
            throw new \InvalidArgumentException('包含不存在的素材');
        }

        if ($cid < 0) {
            throw new \InvalidArgumentException('目标分类无效');
        }
        if ($cid > 0) {
            $category = FileCate::where('id', $cid)->find();
            if (!$category) {
                throw new \InvalidArgumentException('目标分类不存在');
            }
            $categoryType = (int) $category->type;
            foreach ($rows as $row) {
                if ((int) $row['type'] !== $categoryType) {
                    throw new \InvalidArgumentException('素材类型与目标分类不一致');
                }
            }
        }

        File::where([])->whereIn('id', $ids)->update(['cid' => $cid]);
    }

    /** 重命名。 */
    public function rename(int $id, string $name): void
    {
        $this->authorization->requireManagementPermission($this->executionContext->tenantAdmin(), 'peanut.file.rename');
        $name = trim($name);
        if ($id <= 0 || $name === '') {
            throw new \InvalidArgumentException($id <= 0 ? '素材 ID 无效' : '名称不能为空');
        }
        if (mb_strlen($name) > 20) {
            throw new \InvalidArgumentException('名称最多 20 个字符');
        }
        $file = File::where('id', $id)->find();
        if (!$file) {
            throw new \InvalidArgumentException('素材不存在');
        }
        $file->save(['name' => $name]);
    }

    /** 逐项软删素材并删除外部对象；Driver 失败时恢复当前素材，使失败项仍可重试。 */
    public function delete(array $ids): array
    {
        $this->authorization->requireManagementPermission($this->executionContext->tenantAdmin(), 'peanut.file.delete');
        $ids = $this->normalizeIds($ids);
        $rows = File::where([])->whereIn('id', $ids)->order(['id' => 'asc'])->select();
        if ($rows->count() !== count($ids)) {
            throw new \InvalidArgumentException('包含不存在的素材');
        }

        $tenantId = $this->executionContext->tenantId();
        $deleted = 0;
        $storageDeleted = 0;
        foreach ($rows as $row) {
            $fileId = (int) $row['id'];
            $fileKey = (string) $row['file_key'];
            if (File::where([])->where('id', $fileId)->update(['delete_time' => time()]) !== 1) {
                throw new \runtimeException('素材 ' . $fileId . ' 记录删除失败');
            }
            try {
                $this->storage->delete($tenantId, $fileKey);
                $deleted++;
                $storageDeleted++;
            } catch (\Throwable $e) {
                if ((new File())->withTrashed()->where('id', $fileId)->update(['delete_time' => null]) !== 1) {
                    throw new \runtimeException(
                        '素材 ' . $fileId . ' 删除失败且记录恢复失败：' . $e->getMessage(),
                        0,
                        $e,
                    );
                }
                throw new \runtimeException('素材 ' . $fileId . ' 删除失败：' . $e->getMessage(), 0, $e);
            }
        }

        return [
            'files_deleted' => $deleted,
            'storage_deleted' => $storageDeleted,
        ];
    }

    /** 某类型下的稳定分类树（同级按 id 升序）。 */
    public function categoryLists(int $type): array
    {
        if (!FileEnum::isValidType($type)) {
            throw new \InvalidArgumentException('文件类型无效');
        }

        $categories = FileCate::where([])
            ->where('type', $type)
            ->order(['id' => 'asc'])
            ->select()
            ->toArray();
        return linear_to_tree($categories);
    }

    public function addCategory(array $params): void
    {
        $this->authorization->requireManagementPermission($this->executionContext->tenantAdmin(), 'peanut.file.category.add');
        $name = trim((string) ($params['name'] ?? ''));
        if ($name === '') {
            throw new \InvalidArgumentException('分类名称不能为空');
        }
        $type = (int) ($params['type'] ?? 0);
        if (!FileEnum::isValidType($type)) {
            throw new \InvalidArgumentException('文件类型无效');
        }
        $pid = (int) ($params['pid'] ?? 0);
        if ($pid < 0) {
            throw new \InvalidArgumentException('父分类无效');
        }
        if ($pid > 0) {
            $parent = FileCate::where('id', $pid)->find();
            if (!$parent) {
                throw new \InvalidArgumentException('父分类不存在');
            }
            if ((int) $parent->type !== $type) {
                throw new \InvalidArgumentException('父分类类型不一致');
            }
        }

        FileCate::create([
            'pid' => $pid,
            'type' => $type,
            'name' => $name,
        ]);
    }

    public function editCategory(array $params): void
    {
        $this->authorization->requireManagementPermission($this->executionContext->tenantAdmin(), 'peanut.file.category.edit');
        $name = trim((string) ($params['name'] ?? ''));
        if ($name === '') {
            throw new \InvalidArgumentException('分类名称不能为空');
        }
        $category = FileCate::where('id', (int) ($params['id'] ?? 0))->find();
        if (!$category) {
            throw new \InvalidArgumentException('分类不存在');
        }

        $category->save(['name' => $name]);
    }

    /** 删除分类子树、其中素材及存储对象，并返回三者结果。 */
    public function deleteCategory(int $id): array
    {
        $this->authorization->requireManagementPermission($this->executionContext->tenantAdmin(), 'peanut.file.category.delete');
        if ($id <= 0) {
            throw new \InvalidArgumentException('分类 ID 无效');
        }
        $root = FileCate::where('id', $id)->find();
        if (!$root) {
            throw new \InvalidArgumentException('分类不存在');
        }
        $categoryIds = $this->subtreeIds($id, (int) $root->type);
        $fileIds = array_map(
            'intval',
            File::where([])->whereIn('cid', $categoryIds)->column('id'),
        );
        $fileResult = $fileIds === []
            ? ['files_deleted' => 0, 'storage_deleted' => 0]
            : $this->delete($fileIds);

        Db::transaction(function () use ($categoryIds): void {
            $query = FileCate::where([])->whereIn('id', $categoryIds);
            if ($query->count() !== count($categoryIds)) {
                throw new \runtimeException('分类记录删除不完整');
            }
            if ($query->update(['delete_time' => time()]) !== count($categoryIds)) {
                throw new \runtimeException('分类记录删除失败');
            }
        });

        return [
            'categories_deleted' => count($categoryIds),
            'files_deleted' => $fileResult['files_deleted'],
            'storage_deleted' => $fileResult['storage_deleted'],
        ];
    }

    /** 校验根分类类型并返回包含自身的稳定子树 ID。 */
    private function subtreeIds(int $id, int $type): array
    {
        if (!FileEnum::isValidType($type)) {
            throw new \InvalidArgumentException('文件类型无效');
        }

        $categories = FileCate::where([])
            ->order(['id' => 'asc'])
            ->field(['id', 'pid', 'type'])
            ->select()
            ->toArray();
        $children = [];
        $exists = false;
        foreach ($categories as $category) {
            $categoryId = (int) $category['id'];
            $parentId = (int) $category['pid'];
            $children[$parentId][] = $category;
            $exists = $exists || ($categoryId === $id && (int) $category['type'] === $type);
        }
        if (!$exists) {
            throw new \InvalidArgumentException('分类不存在或类型不一致');
        }

        $result = [];
        $queue = [$id];
        while ($queue) {
            $current = array_shift($queue);
            if (in_array($current, $result, true)) {
                throw new \runtimeException('分类子树存在循环关系');
            }
            $result[] = $current;
            foreach ($children[$current] ?? [] as $child) {
                if ((int) $child['type'] !== $type) {
                    throw new \runtimeException('分类子树存在跨类型关系');
                }
                $queue[] = (int) $child['id'];
            }
        }
        return $result;
    }

    private function normalizeIds(array $ids): array
    {
        return PositiveIds::normalize(
            $ids,
            [PositiveIds::REJECT_INVALID, PositiveIds::REQUIRE_NON_EMPTY],
            '素材 ID 无效',
            '素材 ID 集合不能为空',
        );
    }

    private function integerValue(mixed $value, string $message): int
    {
        if (!is_int($value) && !(is_string($value) && preg_match('/^-?\d+$/D', $value) === 1)) {
            throw new \InvalidArgumentException($message);
        }
        return (int) $value;
    }
}
