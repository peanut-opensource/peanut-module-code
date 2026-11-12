<?php

declare(strict_types=1);

use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Modules\ReferenceCodes\Contract\DictionaryQuery;
use PeanutAdmin\Modules\ReferenceCodes\Contract\TenantDictionaryCommands;
use PeanutAdmin\Modules\ReferenceCodes\Infrastructure\ThinkPhpTenantDictionaryProvider;
use PeanutAdmin\Modules\ReferenceCodes\ModuleProvider;
use PeanutAdmin\Modules\ReferenceCodes\Service\DictionaryRuntime;

require dirname(__DIR__, 4) . '/vendor/autoload.php';

$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
    ++$checks;
};

$serverRoot = dirname(__DIR__, 4);
$applicationRoot = dirname($serverRoot);
$moduleRoot = $serverRoot . '/app/modules/peanut/reference_codes';
$manifest = json_decode((string) file_get_contents($moduleRoot . '/module.json'), true, 512, JSON_THROW_ON_ERROR);

$assert($manifest['key'] === 'peanut.reference-codes', 'incorrect native Module owner');
$assert($manifest['backend']['provider'] === ModuleProvider::class, 'native Module provider registration changed');
$assert($manifest['backend']['migrations'] === 'database/migrations', 'native Module no longer owns its schema');
$assert(in_array('pa_dict_type', $manifest['database']['owned_tables'], true)
    && in_array('pa_dict_data', $manifest['database']['owned_tables'], true)
    && in_array('pa_reference_code_set', $manifest['database']['owned_tables'], true),
    'native Module dictionary/reference-code tables were lost');

$provider = new ModuleProvider();
$bindings = $provider->bindings();
$assert($provider->moduleKey() === $manifest['key'], 'provider/manifest mismatch');
$assert(($bindings[DictionaryQuery::class] ?? null) === DictionaryRuntime::class
    && ($bindings[TenantDictionaryCommands::class] ?? null) === DictionaryRuntime::class,
    'host no longer resolves public dictionary contracts through the selected Module');
$assert(is_subclass_of(ThinkPhpTenantDictionaryProvider::class,
    \PeanutAdmin\Modules\ReferenceCodes\Contract\TenantDictionaryCommandProvider::class),
    'tenant-owned dictionary implementation no longer implements its contract');
$reflection = new ReflectionMethod(TenantDictionaryCommands::class, 'replaceType');
$assert($reflection->getParameters()[0]->getType()?->getName() === TenantContext::class,
    'dictionary mutations no longer require a trusted Tenant context');

$tenantProvider = (string) file_get_contents($moduleRoot . '/src/Infrastructure/ThinkPhpTenantDictionaryProvider.php');
$dataService = (string) file_get_contents($moduleRoot . '/src/Service/DictDataApplicationService.php');
$route = (string) file_get_contents($moduleRoot . '/route/app.php');
$assert(str_contains($tenantProvider, 'Db::transaction(')
    && str_contains($tenantProvider, '->lock(true)'),
    'type rename/delete lost its native transaction or row locks');
$assert(str_contains($tenantProvider, "->where('type_id'")
    && str_contains($tenantProvider, "'type_value' =>"),
    'type rename lost dependent entry synchronization');
$assert(str_contains($tenantProvider, 'DICTIONARY_TYPE_IN_USE'),
    'occupied dictionary type deletion no longer fails closed');
$assert(str_contains($tenantProvider, "->where('is_disable', 0)")
    && str_contains($dataService, 'enabledByType'),
    'dictionary enabled-only public query changed');
$assert(str_contains($route, "'peanut.reference-codes.read'")
    && str_contains($route, "'peanut.reference-codes.manage'")
    && str_contains($route, 'AuthMiddleware::class'),
    'read and manage routes no longer declare separate authorization');
$assert(str_contains($route, 'OfficialModuleMiddleware::class'),
    'dictionary endpoint no longer gates selected Module availability');

foreach ([
    'src/Controller/DictTypeController.php',
    'src/Controller/DictDataController.php',
    'src/Validation/DictTypeValidate.php',
    'src/Validation/DictDataValidate.php',
    'src/Service/DictTypeApplicationService.php',
    'src/Service/DictDataApplicationService.php',
    'src/Model/DictType.php',
    'src/Model/DictData.php',
    'src/Infrastructure/ThinkPhpTenantDictionaryProvider.php',
    'src/Infrastructure/ThinkPhpSystemDictionaryProvider.php',
] as $relative) {
    $path = $moduleRoot . '/' . $relative;
    $assert(is_file($path) && !is_link($path), 'missing native Module file: ' . $relative);
    $source = (string) file_get_contents($path);
    $assert(str_contains($source, 'namespace PeanutAdmin\\Modules\\ReferenceCodes\\'),
        'source escaped current Module namespace: ' . $relative);
    $assert(!str_contains($source, 'PeanutAdmin\\ReferenceCodes\\'),
        'source still imports the retired independent Core dictionary: ' . $relative);
}

$zhLocale = (string) file_get_contents($applicationRoot . '/web/src/views/system/dict/locale/zh-CN.ts');
$enLocale = (string) file_get_contents($applicationRoot . '/web/src/views/system/dict/locale/en-US.ts');
$assert(str_contains($zhLocale, '存在字典数据时不能删除'),
    'Chinese delete warning no longer describes rejection');
$assert(str_contains($enLocale, 'cannot be deleted while it contains data'),
    'English delete warning no longer describes rejection');

echo "PB04-REFERENCE-CODES-HOST-001 passed; current Module contract; {$checks} checks; browser/real-MySQL NOT_RUN\n";
