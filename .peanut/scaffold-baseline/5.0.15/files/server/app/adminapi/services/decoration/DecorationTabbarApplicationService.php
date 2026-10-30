<?php

declare(strict_types=1);

namespace app\adminapi\services\decoration;

use app\common\exception\BusinessException;
use app\common\services\decoration\DecorationReadService;
use app\common\services\decoration\DecorationSchemaService;
use app\common\model\decoration\DecorateTabbar;
use app\common\model\decoration\DecorationTabbarSetting;
use think\facade\Db;
use PeanutAdmin\Modules\Article\Contract\ArticleQueries;
use PeanutAdmin\Kernel\Auth\TenantContext;

class DecorationTabbarApplicationService
{
    public function __construct(
        private readonly DecorationReadService $decoration,
        private readonly DecorationSchemaService $schema,
        private readonly ?ArticleQueries $articles = null,
    ) {}

    public function detail(TenantContext $context): array
    {
        return $this->decoration->tabbar($context, forEdit: true);
    }

    public function save(TenantContext $context, array $style, array $items): bool
    {
        try {
            DecorationSchemaService::validateTabbar($context, $style, $items, $this->articles);
        } catch (\RuntimeException $exception) {
            throw BusinessException::invalid('DECORATION_TABBAR_INVALID', $exception->getMessage());
        }
        Db::transaction(function () use ($context, $style, $items): void {
            $setting = DecorationTabbarSetting::where([])->lock(true)->findOrEmpty();
            $storedStyle = json_encode(
                $style,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            );
            if ($setting->isEmpty()) {
                DecorationTabbarSetting::create(['style' => $storedStyle]);
            } else {
                $setting->style = $storedStyle;
                $setting->save();
            }
            DecorateTabbar::where([])->delete();
            $rows = [];
            foreach ($items as $position => $item) {
                $item = $this->schema->resourcesForStorage($item, $context);
                $rows[] = [
                    'position' => $position,
                    'name' => trim((string) $item['name']),
                    'selected' => (string) $item['selected'],
                    'unselected' => (string) $item['unselected'],
                    'link' => json_encode($item['link'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                    'is_show' => (int) $item['is_show'],
                ];
            }
            if ($rows !== []) {
                (new DecorateTabbar())->saveAll($rows);
            }
        });
        return true;
    }
}
