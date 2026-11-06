<?php

declare(strict_types=1);

/** Verify the Article and Category export service and API contracts. */
$checks = 0;
$expect = static function (bool $condition, string $message) use (&$checks): void {
    ++$checks;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$module = dirname(__DIR__, 2) . '/app/modules/peanut/article';
$metadata = require $module . '/api/metadata/openapi.php';
foreach ([
    ['ArticleAdministration', 'Article', 'peanut.article', 'articleLists'],
    ['ArticleCategoryAdministration', 'ArticleCate', 'peanut.article.category', 'categoryLists'],
] as [$contract, $model, $permission, $queryMethod]) {
    $source = (string) file_get_contents($module . '/src/Service/' . $contract . 'Service.php');
    $expect(!str_contains($source, 'EXPORT_UNSUPPORTED'), $contract . ' still rejects every export');
    $expect(!str_contains($source, '(clone $query)->count()'), $contract . ' must not detach deferred scope callbacks from the count query');
    $expect(str_contains($source, 'private readonly XlsxExportService $xlsxExport'), $contract . ' must reuse injected XLSX storage');
    $expect(str_contains($source, 'ExportPageInfo::from(') && str_contains($source, '->rowRange('), $contract . ' bypasses the public paged export protocol');
    $expect(str_contains($source, '$onlyTrashed ? ' . $model . '::onlyTrashed() : ' . $model . '::where([])'), $contract . ' lost ordinary/trash selection');
    $expect(!str_contains($source, 'withoutTenantScope(') && !str_contains($source, 'withoutGlobalScope('), $contract . ' bypasses data scope');
    $expect(str_contains($source, '$this->' . $queryMethod . '($context, $params, false)'), $contract . ' list is not routed through shared query');
    $expect(str_contains($source, '$this->' . $queryMethod . '($context, $params, true)'), $contract . ' recycle list is not routed through shared query');
    foreach (['.list', '.recycle.list'] as $suffix) {
        $operation = $metadata['paths']['/adminapi/' . $permission . $suffix]['get'];
        $parameters = array_column($operation['parameters'], 'name');
        $expect(in_array('export', $parameters, true), $permission . $suffix . ' does not document export');
        $expect(in_array('file_name', $parameters, true), $permission . $suffix . ' does not accept the existing consumer filename');
        $expect(in_array('ARTICLE_EXPORT_RANGE_INVALID', $operation['x-peanut-errors'], true), 'export range failure is undocumented');
    }
}
echo 'ARTICLE-EXPORT-CONTRACT-001 passed: ' . $checks . " checks (no database, no HTTP)\n";
