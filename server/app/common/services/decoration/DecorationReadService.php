<?php

declare(strict_types=1);

namespace app\common\services\decoration;

use app\common\model\decoration\DecorateTabbar;
use app\common\model\decoration\DecoratePage;
use app\common\model\decoration\DecorationTabbarSetting;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Context\TenantSystemContext;

/** 管理端与各客户端共享的只读消费 DTO。 */
final readonly class DecorationReadService
{
    public function __construct(private DecorationSchemaService $schema) {}

    public function pageByType(
        TenantContext|TenantSystemContext $context,
        int $type,
        string $operation = '',
    ): array {
        $page = DecoratePage::where([])
            ->where('type', $type)->findOrEmpty();
        if ($page->isEmpty()) {
            throw new \RuntimeException('装修页面不存在');
        }
        return $this->formatPage($page->toArray());
    }

    public function tabbar(
        TenantContext|TenantSystemContext $context,
        bool $visibleOnly = false,
        string $operation = '',
    ): array {
        $style = $this->tabbarStyle();
        $rows = DecorateTabbar::where([])
            ->order(['position' => 'asc', 'id' => 'asc'])->select()->toArray();
        $list = [];
        foreach ($rows as $item) {
            if ($visibleOnly && (int) $item['is_show'] !== 1) {
                continue;
            }
            $item['link'] = json_decode((string) $item['link'], true) ?: [];
            $list[] = $this->schema->resourcesForRead($item);
        }
        return ['style' => $style, 'list' => $list];
    }

    public function formatPage(array $page): array
    {
        $data = json_decode((string) $page['data'], true, 512, JSON_THROW_ON_ERROR);
        $meta = trim((string) ($page['meta'] ?? '')) === ''
            ? [] : json_decode((string) $page['meta'], true, 512, JSON_THROW_ON_ERROR);
        $page['data'] = $this->schema->resourcesForRead($data);
        $page['meta'] = $this->schema->resourcesForRead($meta);
        return $page;
    }

    /** @return array{default_color:string,selected_color:string} */
    private function tabbarStyle(): array
    {
        $raw = DecorationTabbarSetting::where([])->value('style');
        if ($raw === null) {
            return ['default_color' => '#666666', 'selected_color' => '#2F80ED'];
        }
        $style = json_decode((string) $raw, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($style) || array_is_list($style)) {
            throw new \RuntimeException('Tabbar 样式配置无效');
        }

        return $style;
    }
}
