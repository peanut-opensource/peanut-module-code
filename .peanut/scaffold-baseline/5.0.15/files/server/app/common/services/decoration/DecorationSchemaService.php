<?php

declare(strict_types=1);

namespace app\common\services\decoration;

use app\common\enum\decoration\DecorationEnum;
use app\common\services\ProductAssetReferenceService;
use PeanutAdmin\Modules\Article\Contract\ArticleQueries;

/** 业务装修 Schema、链接语义与资源 URI 的单一边界。 */
class DecorationSchemaService
{
    private const COMPONENTS = [
        DecorationEnum::MOBILE_HOME => ['search', 'banner', 'nav', 'middle-banner', 'news'],
        DecorationEnum::MOBILE_PROFILE => ['user-info', 'my-service', 'user-banner'],
        DecorationEnum::MOBILE_CUSTOMER_SERVICE => ['customer-service'],
        DecorationEnum::PC_HOME => ['pc-banner'],
    ];
    private const FIXED_COMPONENTS = ['search', 'news', 'user-info'];
    private const IMAGE_KEYS = ['image', 'bg', 'bg_image', 'title_img', 'qrcode', 'selected', 'unselected'];
    private const SHOP_TARGETS = [
        'home', 'news', 'profile', 'settings', 'favorites',
        'customer_service', 'wallet', 'privacy', 'service',
    ];
    private const THEMES = [
        1 => ['#2F80ED', '#56CCF2', 'white'],
        2 => ['#2EC840', '#3DE650', 'white'],
        3 => ['#A74BFD', '#CB60FF', 'white'],
        4 => ['#F7971E', '#FFD200', 'black'],
        5 => ['#FF2C3C', '#EF1D2D', 'white'],
        6 => ['#FD498F', '#FA444D', 'white'],
    ];

    public function __construct(private readonly ProductAssetReferenceService $assets) {}

    public static function validatePage(
        object $context,
        int $type,
        mixed $data,
        mixed $meta,
        ?ArticleQueries $articles,
    ): void {
        if (!in_array($type, DecorationEnum::ALL_TYPES, true)) {
            throw new \RuntimeException('装修页面类型无效');
        }
        if ($type === DecorationEnum::SYSTEM_THEME) {
            self::validateTheme($data);
            if ($meta !== [] && $meta !== null && $meta !== '') {
                throw new \RuntimeException('系统风格不接受页面元数据');
            }
            return;
        }
        if (!is_array($data) || array_is_list($data) === false) {
            throw new \RuntimeException('页面组件必须为数组');
        }
        self::validateComponentSet($context, $type, $data, $articles);
        self::validateMeta($type, $meta);
    }

    public static function validateTabbar(
        object $context,
        array $style,
        array $items,
        ?ArticleQueries $articles,
    ): void {
        self::color((string) ($style['default_color'] ?? ''), 'Tabbar 默认颜色');
        self::color((string) ($style['selected_color'] ?? ''), 'Tabbar 选中颜色');
        if (count($items) < 2 || count($items) > 5) {
            throw new \RuntimeException('Tabbar 总项数必须为 2～5 项');
        }
        $visible = 0;
        foreach ($items as $position => $item) {
            if (!is_array($item)) {
                throw new \RuntimeException('Tabbar 项格式无效');
            }
            $name = trim((string) ($item['name'] ?? ''));
            if ($name === '' || mb_strlen($name) > 20) {
                throw new \RuntimeException('Tabbar 名称不能为空且最多 20 个字');
            }
            $isShow = (int) ($item['is_show'] ?? -1);
            if (!in_array($isShow, [0, 1], true)) {
                throw new \RuntimeException('Tabbar 显示状态无效');
            }
            $visible += $isShow;
            self::validateLink($context, $item['link'] ?? null, false, $articles);
            foreach (['selected', 'unselected'] as $field) {
                if (!is_string($item[$field] ?? null)) {
                    throw new \RuntimeException('Tabbar 图标格式无效');
                }
            }
            if ($position === 0) {
                $link = $item['link'];
                if ($isShow !== 1 || ($link['target_type'] ?? '') !== 'shop' || ($link['target'] ?? '') !== 'home') {
                    throw new \RuntimeException('Tabbar 首项必须显示且固定指向首页');
                }
            }
        }
        if ($visible < 2) {
            throw new \RuntimeException('Tabbar 至少保留 2 个可见项');
        }
    }

    public function resourcesForStorage(
        mixed $value,
        ?object $context = null,
    ): mixed {
        return $this->transformResources($value, false, null, $context);
    }

    public function resourcesForRead(mixed $value): mixed
    {
        return $this->transformResources($value, true);
    }

    public static function validateLink(
        object $context,
        mixed $link,
        bool $pcTarget,
        ?ArticleQueries $articles,
    ): void {
        if (!is_array($link)) {
            throw new \RuntimeException('装修链接格式无效');
        }
        $type = (string) ($link['target_type'] ?? '');
        $target = $link['target'] ?? null;
        if (!in_array($type, ['shop', 'article', 'custom', 'mini_program'], true)) {
            throw new \RuntimeException('装修链接类型无效');
        }
        if ($type === 'shop' && !in_array((string) $target, self::SHOP_TARGETS, true)) {
            throw new \RuntimeException('站内链接目标无效');
        }
        if ($type === 'article') {
            $articleId = filter_var($target, FILTER_VALIDATE_INT);
            if ($articleId === false || $articleId <= 0
                || !$context instanceof \PeanutAdmin\Kernel\Auth\TenantContext
                || $articles === null || !$articles->visible($context, $articleId)) {
                throw new \RuntimeException('文章链接必须指向存在且可见的文章');
            }
        }
        if ($type === 'custom' && !self::absoluteHttpUrl((string) $target)) {
            throw new \RuntimeException('自定义链接必须为 http/https 绝对地址');
        }
        if ($type === 'mini_program') {
            $query = $link['query'] ?? null;
            if (!is_string($target) || trim($target) === '' || !is_array($query)
                || trim((string) ($query['app_id'] ?? '')) === ''
                || !in_array((string) ($query['env_version'] ?? ''), ['develop', 'trial', 'release'], true)) {
                throw new \RuntimeException('小程序链接必须包含页面、AppID 和环境版本');
            }
            if ($pcTarget && !self::absoluteHttpUrl((string) ($query['web_url'] ?? ''))) {
                throw new \RuntimeException('PC 小程序链接必须提供 http/https 回退网址');
            }
        }
        if (isset($link['query']) && !is_array($link['query'])) {
            throw new \RuntimeException('装修链接参数格式无效');
        }
    }

    private static function validateComponentSet(
        object $context,
        int $type,
        array $components,
        ?ArticleQueries $articles,
    ): void {
        $expected = self::COMPONENTS[$type];
        $names = array_map(static fn($item) => is_array($item) ? (string) ($item['name'] ?? '') : '', $components);
        $sortedNames = $names;
        $sortedExpected = $expected;
        sort($sortedNames);
        sort($sortedExpected);
        if ($sortedNames !== $sortedExpected || count(array_unique($names)) !== count($names)) {
            throw new \RuntimeException('页面组件集合不完整或包含重复组件');
        }
        foreach ($components as $component) {
            self::validateEnvelope($component);
            $name = (string) $component['name'];
            if (in_array($name, self::FIXED_COMPONENTS, true) && (int) ($component['disabled'] ?? 0) !== 1) {
                throw new \RuntimeException($name . ' 为固定组件，必须保持锁定');
            }
            self::validateComponent($context, $name, $component['content'], $articles);
            if ($name === 'pc-banner') {
                self::validatePcStyles($component['styles']);
            }
        }
    }

    private static function validateEnvelope(array $component): void
    {
        if (trim((string) ($component['title'] ?? '')) === ''
            || trim((string) ($component['name'] ?? '')) === ''
            || !is_array($component['content'] ?? null)
            || !is_array($component['styles'] ?? null)) {
            throw new \RuntimeException('装修组件信封格式无效');
        }
        if (isset($component['disabled']) && !in_array((int) $component['disabled'], [0, 1], true)) {
            throw new \RuntimeException('组件锁定状态无效');
        }
    }

    private static function validateComponent(
        object $context,
        string $name,
        array $content,
        ?ArticleQueries $articles,
    ): void {
        if (in_array($name, self::FIXED_COMPONENTS, true)) {
            if ($content !== []) {
                throw new \RuntimeException($name . ' 固定组件内容必须为空');
            }
            return;
        }
        if ($name === 'banner') {
            self::binary($content['enabled'] ?? null, '轮播图启用状态');
            self::oneOfInt($content['style'] ?? null, [1, 2], '轮播图样式');
            self::oneOfInt($content['bg_style'] ?? null, [1, 2], '轮播图背景样式');
            self::validateItems($context, $content['data'] ?? null, 1, 5, true, false, $articles);
            return;
        }
        if (in_array($name, ['middle-banner', 'user-banner'], true)) {
            self::binary($content['enabled'] ?? null, '广告启用状态');
            self::validateItems($context, $content['data'] ?? null, 1, 5, true, false, $articles);
            return;
        }
        if ($name === 'nav') {
            self::binary($content['enabled'] ?? null, '导航启用状态');
            self::oneOfInt($content['style'] ?? null, [1, 2], '导航样式');
            self::rangeInt($content['per_line'] ?? null, 1, 5, '每行导航数');
            self::rangeInt($content['show_line'] ?? null, 1, 2, '导航显示行数');
            self::validateItems($context, $content['data'] ?? null, 1, 100, true, false, $articles);
            return;
        }
        if ($name === 'my-service') {
            self::binary($content['enabled'] ?? null, '服务启用状态');
            self::oneOfInt($content['style'] ?? null, [1, 2], '服务样式');
            if (mb_strlen(trim((string) ($content['title'] ?? ''))) > 20) {
                throw new \RuntimeException('服务标题最多 20 个字');
            }
            self::validateItems($context, $content['data'] ?? null, 1, 100, true, false, $articles);
            return;
        }
        if ($name === 'customer-service') {
            foreach (['title', 'time', 'mobile'] as $field) {
                if (mb_strlen(trim((string) ($content[$field] ?? ''))) > 20) {
                    throw new \RuntimeException('客服标题、时间和电话最多 20 个字');
                }
            }
            foreach (['qrcode', 'remark'] as $field) {
                if (!is_string($content[$field] ?? null)) {
                    throw new \RuntimeException('客服配置格式无效');
                }
            }
            return;
        }
        if ($name === 'pc-banner') {
            self::binary($content['enabled'] ?? null, 'PC Banner 启用状态');
            self::validateItems($context, $content['data'] ?? null, 1, 10, false, true, $articles);
            return;
        }
        throw new \RuntimeException('未知装修组件');
    }

    private static function validateItems(
        object $context,
        mixed $items,
        int $min,
        int $max,
        bool $showAllowed,
        bool $pcTarget,
        ?ArticleQueries $articles,
    ): void {
        if (!is_array($items) || count($items) < $min || count($items) > $max) {
            throw new \RuntimeException("装修组件条目必须为 {$min}～{$max} 项");
        }
        foreach ($items as $item) {
            if (!is_array($item) || !is_string($item['image'] ?? null) || !is_string($item['name'] ?? null)) {
                throw new \RuntimeException('装修组件条目格式无效');
            }
            self::validateLink($context, $item['link'] ?? null, $pcTarget, $articles);
            if ($showAllowed && isset($item['is_show'])) {
                self::binary($item['is_show'], '条目显示状态');
            }
        }
    }

    private static function validatePcStyles(array $styles): void
    {
        $allowed = ['position', 'left', 'top', 'width', 'height'];
        if (array_diff(array_keys($styles), $allowed) !== []
            || array_diff($allowed, array_keys($styles)) !== []) {
            throw new \RuntimeException('PC Banner 样式字段无效');
        }
        if (!in_array((string) $styles['position'], ['absolute', 'relative'], true)) {
            throw new \RuntimeException('PC Banner 定位方式无效');
        }
        foreach (['left', 'top'] as $field) {
            if (!preg_match('/^-?\d+(?:\.\d+)?(?:px|%)$/', (string) $styles[$field])) {
                throw new \RuntimeException('PC Banner 偏移必须使用 px 或 %');
            }
        }
        foreach (['width', 'height'] as $field) {
            if (!preg_match('/^\d+(?:\.\d+)?(?:px|%)$/', (string) $styles[$field])) {
                throw new \RuntimeException('PC Banner 尺寸必须使用 px 或 %');
            }
        }
    }

    private static function validateMeta(int $type, mixed $meta): void
    {
        if (in_array($type, [DecorationEnum::MOBILE_HOME, DecorationEnum::MOBILE_PROFILE], true)) {
            if (!is_array($meta) || count($meta) !== 1 || ($meta[0]['name'] ?? '') !== 'page-meta') {
                throw new \RuntimeException('页面必须且只能包含一个 page-meta');
            }
            self::validateEnvelope($meta[0]);
            $content = $meta[0]['content'];
            if (mb_strlen(trim((string) ($content['title'] ?? ''))) > 8) {
                throw new \RuntimeException('页面标题最多 8 个字');
            }
            foreach (['title_type', 'bg_type', 'text_color'] as $field) {
                self::oneOfInt($content[$field] ?? null, [1, 2], '页面样式');
            }
            self::color((string) ($content['bg_color'] ?? ''), '页面背景色');
            foreach (['bg_image', 'title_img'] as $field) {
                if (!is_string($content[$field] ?? null)) {
                    throw new \RuntimeException('页面图片格式无效');
                }
            }
            return;
        }
        if ($meta !== [] && $meta !== null && $meta !== '') {
            throw new \RuntimeException('当前页面不接受元数据组件');
        }
    }

    private static function validateTheme(mixed $theme): void
    {
        if (!is_array($theme) || array_is_list($theme)) {
            throw new \RuntimeException('系统风格格式无效');
        }
        $id = (int) ($theme['themeColorId'] ?? 0);
        self::rangeInt($id, 1, 7, '主题编号');
        foreach (['themeColor1', 'themeColor2', 'navigationBarColor'] as $field) {
            self::color((string) ($theme[$field] ?? ''), '主题颜色');
        }
        foreach (['topTextColor', 'buttonColor'] as $field) {
            if (!in_array((string) ($theme[$field] ?? ''), ['white', 'black'], true)) {
                throw new \RuntimeException('主题文字颜色无效');
            }
        }
        if ($id !== 7) {
            [$color1, $color2, $button] = self::THEMES[$id];
            if (strtoupper((string) $theme['themeColor1']) !== strtoupper($color1)
                || strtoupper((string) $theme['themeColor2']) !== strtoupper($color2)
                || (string) $theme['buttonColor'] !== $button) {
                throw new \RuntimeException('预设主题颜色不可自定义；请选择自定义主题');
            }
        }
    }

    private function transformResources(
        mixed $value,
        bool $absolute,
        ?string $key = null,
        ?object $context = null,
    ): mixed {
        if (is_array($value)) {
            foreach ($value as $childKey => $child) {
                $value[$childKey] = $this->transformResources(
                    $child,
                    $absolute,
                    (string) $childKey,
                    $context,
                );
            }
            return $value;
        }
        if (!is_string($value) || $value === '' || !in_array((string) $key, self::IMAGE_KEYS, true)) {
            return $value;
        }
        if ($absolute) {
            return $this->assets->forRead($value);
        }
        return $this->assets->forStorage($value, null, $context);
    }

    private static function binary(mixed $value, string $label): void
    {
        self::oneOfInt($value, [0, 1], $label);
    }

    private static function oneOfInt(mixed $value, array $allowed, string $label): void
    {
        if (filter_var($value, FILTER_VALIDATE_INT) === false || !in_array((int) $value, $allowed, true)) {
            throw new \RuntimeException($label . '无效');
        }
    }

    private static function rangeInt(mixed $value, int $min, int $max, string $label): void
    {
        if (filter_var($value, FILTER_VALIDATE_INT) === false || (int) $value < $min || (int) $value > $max) {
            throw new \RuntimeException($label . "必须为 {$min}～{$max}");
        }
    }

    private static function color(string $value, string $label): void
    {
        if (!preg_match('/^#[0-9a-f]{6}$/i', $value)) {
            throw new \RuntimeException($label . '必须为十六进制颜色');
        }
    }

    private static function absoluteHttpUrl(string $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_URL) !== false
            && in_array(strtolower((string) parse_url($value, PHP_URL_SCHEME)), ['http', 'https'], true);
    }
}
