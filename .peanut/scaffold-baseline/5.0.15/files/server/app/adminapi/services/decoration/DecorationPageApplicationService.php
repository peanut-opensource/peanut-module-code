<?php

declare(strict_types=1);

namespace app\adminapi\services\decoration;

use app\common\exception\BusinessException;
use app\common\services\ProductAssetReferenceService;
use PeanutAdmin\Modules\Article\Contract\ArticleQueries;
use app\common\services\decoration\DecorationReadService;
use app\common\services\decoration\DecorationSchemaService;
use app\common\model\decoration\DecoratePage;
use think\facade\Db;
use app\common\infrastructure\module\ModuleExecutionBoundary;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Module\ModuleException;

class DecorationPageApplicationService
{
    public function __construct(
        private readonly DecorationReadService $decoration,
        private readonly DecorationSchemaService $schema,
        private readonly ProductAssetReferenceService $assets,
        private readonly ModuleExecutionBoundary $modules,
        private readonly ?ArticleQueries $articles = null,
    ) {}

    public function lists(TenantContext $context, array $allowedTypes): array
    {
        return DecoratePage::where([])
            ->field(['id', 'type', 'name', 'update_time'])
            ->whereIn('type', $allowedTypes)->order('type', 'asc')->select()->toArray();
    }

    public function detail(TenantContext $context, int $id, array $allowedTypes): array
    {
        $page = DecoratePage::where('id', $id)->findOrEmpty();
        if ($page->isEmpty() || !in_array((int) $page->type, $allowedTypes, true)) {
            throw BusinessException::notFound('DECORATION_PAGE_NOT_FOUND', '装修页面不存在或无权访问');
        }
        return $this->decoration->formatPage($page->toArray(), forEdit: true);
    }

    public function detailByType(TenantContext $context, int $type): array
    {
        return $this->decoration->pageByType($context, $type);
    }

    public function save(TenantContext $context, array $params, array $allowedTypes): bool
    {
        $type = (int) $params['type'];
        if (!in_array($type, $allowedTypes, true)) {
            throw BusinessException::forbidden('DECORATION_PAGE_WRITE_FORBIDDEN', '无权保存该装修页面');
        }
        try {
            $data = $params['data'];
            $meta = $params['meta'] ?? [];
            DecorationSchemaService::validatePage($context, $type, $data, $meta, $this->articles);
        } catch (\RuntimeException $exception) {
            throw BusinessException::invalid('DECORATION_PAGE_INVALID', $exception->getMessage());
        }
        Db::transaction(function () use ($context, $params, $type, $data, $meta): void {
            $page = DecoratePage::where([])
                ->where('id', (int) $params['id'])->lock(true)->findOrEmpty();
            if ($page->isEmpty()) {
                throw BusinessException::notFound('DECORATION_PAGE_NOT_FOUND', '装修页面不存在');
            }
            if ((int) $page->type !== $type) {
                throw BusinessException::conflict('DECORATION_PAGE_TYPE_IMMUTABLE', '装修页面类型不可修改');
            }
            $page->data = json_encode(
                $this->schema->resourcesForStorage($data, $context),
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            );
            $page->meta = json_encode(
                $this->schema->resourcesForStorage($meta, $context),
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            );
            $page->save();
        });
        return true;
    }

    public function articleOptions(TenantContext $context, int $limit): array
    {
        try {
            $this->modules->assertHttp('peanut.article', 'http.admin');
        } catch (ModuleException) {
            return [];
        }
        $rows = ($this->articles ?? throw new \DomainException('ARTICLE_MODULE_UNAVAILABLE'))->options($context, $limit);
        foreach ($rows as &$row) {
            $row['image'] = $this->assets->forRead((string) ($row['image'] ?? ''));
        }
        unset($row);
        return $rows;
    }

}
