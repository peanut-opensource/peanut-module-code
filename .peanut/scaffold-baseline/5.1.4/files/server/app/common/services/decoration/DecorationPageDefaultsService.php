<?php

declare(strict_types=1);

namespace app\common\services\decoration;

use app\common\enum\decoration\DecorationEnum;
use app\common\model\decoration\DecoratePage;
use think\DbManager;

/** Application page structure shared by tenant provisioning and explicit maintenance. */
final readonly class DecorationPageDefaultsService
{
    public function __construct(private DbManager $database) {}

    /** @param list<int> $types */
    public function ensureMissing(array $types = DecorationEnum::ALL_TYPES, bool $dryRun = false): array
    {
        if ($types === [] || !array_is_list($types)) {
            throw new \InvalidArgumentException('DECORATION_PAGE_TYPES_INVALID');
        }
        foreach ($types as $type) {
            if (!in_array($type, DecorationEnum::ALL_TYPES, true)) {
                throw new \InvalidArgumentException('DECORATION_PAGE_TYPES_INVALID');
            }
        }
        $types = array_values(array_unique($types));
        sort($types);
        // Empty shells are application structure; selected Modules supply business content.
        $pages = [
            [1, '移动端首页', '[]', '[{"title":"页面设置","name":"page-meta","content":{"title":"首页","title_type":1,"title_img":"","bg_type":1,"bg_color":"#2F80ED","bg_image":"","text_color":1},"styles":{}}]'],
            [2, '个人中心', '[]', '[{"title":"页面设置","name":"page-meta","content":{"title":"个人中心","title_type":1,"title_img":"","bg_type":1,"bg_color":"#2F80ED","bg_image":"","text_color":1},"styles":{}}]'],
            [3, '客服设置', '[]', '[]'],
            [4, 'PC 首页', '[]', '[]'],
            [5, '系统风格', '{"themeColorId":3,"topTextColor":"white","navigationBarColor":"#A74BFD","themeColor1":"#A74BFD","themeColor2":"#CB60FF","buttonColor":"white"}', '[]'],
        ];
        $complete = function () use ($types, $dryRun, $pages): array {
            $existing = array_map('intval', DecoratePage::whereIn('type', $types)->column('type'));
            $missing = array_values(array_diff($types, $existing));
            $rows = [];
            foreach ($pages as [$type, $name, $data, $meta]) {
                if (in_array($type, $missing, true)) {
                    $rows[] = compact('type', 'name', 'data', 'meta') + ['create_time' => 0, 'update_time' => 0];
                }
            }
            if (!$dryRun && $rows !== []) {
                DecoratePage::saveAll($rows, false);
            }
            return ['requested_types' => $types, 'missing_types' => $missing, 'inserted' => $dryRun ? 0 : count($rows)];
        };
        return $dryRun ? $complete() : $this->database->transaction($complete);
    }
}
