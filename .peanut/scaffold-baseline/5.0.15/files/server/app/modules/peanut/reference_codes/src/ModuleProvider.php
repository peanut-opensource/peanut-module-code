<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\ReferenceCodes;

use PeanutAdmin\Modules\ReferenceCodes\Contract\SystemDictionaryProvider;
use PeanutAdmin\Modules\ReferenceCodes\Contract\TenantDictionaryCommandProvider;
use PeanutAdmin\Modules\ReferenceCodes\Contract\TenantDictionaryQueryProvider;
use PeanutAdmin\Modules\ReferenceCodes\Contract\DictionaryQuery;
use PeanutAdmin\Modules\ReferenceCodes\Contract\SystemReferenceCodeQuery;
use PeanutAdmin\Modules\ReferenceCodes\Contract\TenantDictionaryCommands;
use PeanutAdmin\Modules\ReferenceCodes\Infrastructure\ThinkPhpSystemDictionaryProvider;
use PeanutAdmin\Modules\ReferenceCodes\Infrastructure\ThinkPhpTenantDictionaryProvider;
use PeanutAdmin\Modules\ReferenceCodes\Service\DictionaryRuntime;
use PeanutAdmin\Modules\ReferenceCodes\Versioned\Definition\DeployedReferenceCodeSetRegistry;
use PeanutAdmin\Modules\ReferenceCodes\Versioned\Definition\ReferenceCodeSetRegistry;
use PeanutAdmin\Kernel\Module\ModuleProvider as ModuleProviderContract;
use think\App;

final class ModuleProvider implements ModuleProviderContract
{
    public function moduleKey(): string
    {
        return 'peanut.reference-codes';
    }

    public function bindings(): array
    {
        return [
            TenantDictionaryQueryProvider::class => ThinkPhpTenantDictionaryProvider::class,
            TenantDictionaryCommandProvider::class => ThinkPhpTenantDictionaryProvider::class,
            SystemDictionaryProvider::class => ThinkPhpSystemDictionaryProvider::class,
            DictionaryQuery::class => DictionaryRuntime::class,
            TenantDictionaryCommands::class => DictionaryRuntime::class,
            SystemReferenceCodeQuery::class => DictionaryRuntime::class,
            ReferenceCodeSetRegistry::class => fn(App $app): ReferenceCodeSetRegistry =>
                $app->make(DeployedReferenceCodeSetRegistry::class)->build(),
        ];
    }
}
