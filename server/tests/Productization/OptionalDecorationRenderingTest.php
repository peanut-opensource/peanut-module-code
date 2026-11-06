<?php

declare(strict_types=1);

$root = dirname(__DIR__, 3);
$sources = [
    'mobile-home' => file_get_contents($root . '/uniapp/src/modules/peanut-article/components/HomeContent.vue'),
    'mobile-profile' => file_get_contents($root . '/uniapp/src/modules/peanut-member/pages/user/user.vue'),
    'pc-runtime' => file_get_contents($root . '/pc/modules/peanut-article/components/HomeContent.vue'),
];
$matrix = [
    ['mobile-home', 'v-for="decorationComponent in renderComponents"', '首页未按保存的组件数组顺序渲染'],
    ['mobile-profile', 'v-for="decorationComponent in renderComponents"', '个人页未按保存的组件数组顺序渲染'],
    ['mobile-home', 'data-decoration-component="search"', '首页搜索组件未渲染'],
    ['mobile-home', 'banner-style-', 'Banner style 未消费'],
    ['mobile-home', 'content.bg_style', 'Banner bg_style 未消费'],
    ['mobile-home', 'content.show_line', '导航 show_line 未消费'],
    ['mobile-home', 'content.per_line', '导航 per_line 未消费'],
    ['mobile-profile', 'service-style-', '我的服务 style 未消费'],
    ['mobile-home', 'meta.title_img', '首页 title_img 未消费'],
    ['mobile-profile', 'meta.title_img', '个人页 title_img 未消费'],
    ['mobile-home', 'meta.value.text_color', '首页 text_color 未消费'],
    ['mobile-profile', 'meta.value.text_color', '个人页 text_color 未消费'],
    ['mobile-profile', 'meta.value.bg_image', '个人页 bg_image 未消费'],
    ['pc-runtime', 'safeCssLength', 'PC Runtime 未防御性过滤装修样式'],
];
foreach ($matrix as [$source, $needle, $message]) {
    if (!str_contains($sources[$source], $needle)) {
        throw new RuntimeException($message);
    }
}
echo 'Optional decoration rendering contracts passed: ', count($matrix), PHP_EOL;
