<?php

declare(strict_types=1);

/** 验证原生 Validate 场景的可选筛选；不连接数据库，不以服务替身代替 HTTP 验收。 */
$root = dirname(__DIR__, 3);
if (!class_exists(\think\Validate::class)) {
    require_once $root . '/server/vendor/autoload.php';
}
require_once $root . '/server/app/modules/peanut/article/src/Validation/ArticleValidate.php';
require_once $root . '/server/app/modules/peanut/article/src/Validation/ArticleCateValidate.php';

$checks = 0;
$verify = static function (string $class, string $scene, array $data, bool $expected) use (&$checks): void {
    $validator = new $class();
    $actual = $validator->scene($scene)->check($data);
    ++$checks;
    if ($actual !== $expected) {
        throw new RuntimeException($class . ':' . $scene . ':' . json_encode(array_keys($data)) . ':' . $validator->getError());
    }
};
$article = PeanutAdmin\Modules\Article\Validation\ArticleValidate::class;
$category = PeanutAdmin\Modules\Article\Validation\ArticleCateValidate::class;
$verify($article, 'lists', [], true);
$verify($article, 'recycle', [], true);
$verify($article, 'recycle', ['title' => 'synthetic-filter'], true);
$verify($article, 'recycle', ['cid' => 999, 'page_size' => 25, 'export' => 1], true);
$verify($article, 'recycle', ['cid' => 0], false);
$verify($article, 'recycle', ['cid' => -1], false);
$verify($article, 'recycle', ['cid' => 'invalid'], false);
$verify($article, 'recycle', ['is_show' => 3], false);
$verify($article, 'recycle', ['page_size' => 0], false);
$verify($article, 'recycle', ['export' => 3], false);
$verify($article, 'add', [], false);
$verify($article, 'recycleDetail', ['id' => 1], true);
$verify($article, 'restore', ['id' => 1], true);
$verify($article, 'restore', [], false);
$verify($category, 'lists', [], true);
$verify($category, 'recycle', [], true);
$verify($category, 'recycle', ['name' => 'synthetic-filter'], true);
$verify($category, 'add', [], false);
$edit = ['id' => 1, 'name' => 'synthetic-category', 'is_show' => 1, 'sort' => 7];
$verify($category, 'edit', $edit, true);
$verify($category, 'edit', array_replace($edit, ['id' => '1']), true);
$verify($category, 'edit', array_replace($edit, ['id' => '01']), true);
foreach ([0, -1, 1.5, '1.5', 'invalid'] as $id) {
    $verify($category, 'edit', array_replace($edit, ['id' => $id]), false);
}
$verify($category, 'edit', array_diff_key($edit, ['id' => true]), false);
$verify($category, 'edit', array_diff_key($edit, ['name' => true]), false);
$verify($category, 'edit', array_replace($edit, ['name' => str_repeat('x', 91)]), false);
$verify($category, 'edit', array_replace($edit, ['is_show' => 2]), false);
$verify($category, 'edit', array_replace($edit, ['sort' => -1]), false);
echo 'ARTICLE-VALIDATION-SCENES-001 checks=' . $checks . " passed; database-not-executed\n";
