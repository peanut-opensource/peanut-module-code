<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\ReferenceCodes;

use app\common\contract\idempotency\IdempotentCommandExecutor;
use PeanutAdmin\Modules\ReferenceCodes\Application\DictionaryService;
use PeanutAdmin\Modules\ReferenceCodes\Contract\SystemDictionaryProvider;
use PeanutAdmin\Modules\ReferenceCodes\Contract\TenantDictionaryCommandProvider;
use PeanutAdmin\Modules\ReferenceCodes\Contract\TenantDictionaryQueryProvider;
use PeanutAdmin\Modules\ReferenceCodes\Contract\DictionaryQuery;
use PeanutAdmin\Modules\ReferenceCodes\Contract\SystemReferenceCodeQuery;
use PeanutAdmin\Modules\ReferenceCodes\Contract\TenantDictionaryCommands;
use PeanutAdmin\Modules\ReferenceCodes\Infrastructure\ThinkPhpSystemDictionaryProvider;
use PeanutAdmin\Modules\ReferenceCodes\Infrastructure\ThinkPhpTenantDictionaryProvider;
use PeanutAdmin\Modules\ReferenceCodes\Service\DictionaryRuntime;
use PeanutAdmin\Modules\ReferenceCodes\Service\ReferenceCodesHttpApplicationService;
use PeanutAdmin\Modules\ReferenceCodes\Versioned\Application\ReferenceCodeAdminService;
use PeanutAdmin\Modules\ReferenceCodes\Versioned\Application\ReferenceCodeQuery;
use PeanutAdmin\Modules\ReferenceCodes\Versioned\Definition\DeployedReferenceCodeSetRegistry;
use PeanutAdmin\Modules\ReferenceCodes\Versioned\Definition\ReferenceCodeSetRegistry;
use PeanutAdmin\Modules\ReferenceCodes\Versioned\Persistence\ReferenceCodeStore;
use PeanutAdmin\Kernel\Module\ModuleProvider as ModuleProviderContract;
use PeanutAdmin\Kernel\Module\ModuleRuntimeRepository;
use PeanutAdmin\Modules\Identity\Contract\AdminDirectoryQuery;
use PeanutAdmin\Modules\Identity\Contract\TenantMemberDirectory;
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
            DictionaryService::class => fn(App $app): DictionaryService => new DictionaryService(
                $app->make(TenantDictionaryQueryProvider::class),
                $app->make(TenantDictionaryCommandProvider::class),
                $app->make(SystemDictionaryProvider::class),
            ),
            DictionaryRuntime::class => fn(App $app): DictionaryRuntime => new DictionaryRuntime(
                $app->make(DictionaryService::class),
                $app->make(SystemDictionaryProvider::class),
            ),
            DictionaryQuery::class => DictionaryRuntime::class,
            TenantDictionaryCommands::class => DictionaryRuntime::class,
            SystemReferenceCodeQuery::class => DictionaryRuntime::class,
            ReferenceCodeSetRegistry::class => fn(App $app): ReferenceCodeSetRegistry =>
                $app->make(DeployedReferenceCodeSetRegistry::class)->build(),
            ReferenceCodeStore::class => fn(App $app): ReferenceCodeStore => new ReferenceCodeStore(
                $app->make(TenantMemberDirectory::class),
                $app->make(AdminDirectoryQuery::class),
            ),
            ReferenceCodeQuery::class => fn(App $app): ReferenceCodeQuery => new ReferenceCodeQuery($app->make(ReferenceCodeStore::class)),
            ReferenceCodeAdminService::class => fn(App $app): ReferenceCodeAdminService => new ReferenceCodeAdminService($app->make(ReferenceCodeStore::class)),
            ReferenceCodesHttpApplicationService::class => fn(App $app): ReferenceCodesHttpApplicationService => new ReferenceCodesHttpApplicationService(
                $app->make(ReferenceCodeSetRegistry::class),
                $app->make(ReferenceCodeQuery::class),
                $app->make(ReferenceCodeAdminService::class),
                $app->make(IdempotentCommandExecutor::class),
                $app->make(ModuleRuntimeRepository::class),
                $app->get(\app\common\execution\CurrentExecutionContext::class),
                $app->get(\app\common\contract\authorization\AdminAuthorizationQuery::class),
            ),
        ];
    }
}
