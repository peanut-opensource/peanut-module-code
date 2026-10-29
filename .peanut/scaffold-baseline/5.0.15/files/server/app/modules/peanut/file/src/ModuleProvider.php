<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\File;

use PeanutAdmin\Modules\File\Service\FileAdministrationService;
use PeanutAdmin\Modules\File\Service\FileUploadService;
use PeanutAdmin\Modules\File\Contract\FileAdministration;
use PeanutAdmin\Modules\File\Contract\FileUploads;
use PeanutAdmin\Modules\File\Contract\FileStorage;
use PeanutAdmin\Modules\File\Contract\FileReferences;
use PeanutAdmin\Modules\File\Contract\FileDerivatives;
use PeanutAdmin\Modules\File\Contract\StorageConfiguration;
use PeanutAdmin\Modules\File\Service\FileService;
use PeanutAdmin\Modules\File\Service\FileDerivativeService;
use PeanutAdmin\Modules\File\Service\Storage\StorageService;
use PeanutAdmin\Modules\File\Service\Storage\StorageConfigurationService;
use PeanutAdmin\Kernel\Module\ModuleProvider as ModuleProviderContract;

final class ModuleProvider implements ModuleProviderContract
{
    public function moduleKey(): string
    {
        return 'peanut.file';
    }

    public function bindings(): array
    {
        return [
            \PeanutAdmin\Modules\File\Contract\FileVisibility::class => \PeanutAdmin\Modules\File\Service\FileVisibilityService::class,
            \PeanutAdmin\Modules\File\Contract\FileDocumentSnapshots::class => StorageService::class,
            \PeanutAdmin\Modules\File\Contract\FileAccess::class => \PeanutAdmin\Modules\File\Service\FileAccessService::class,
            \PeanutAdmin\Modules\File\Contract\FileReferenceCommands::class => \PeanutAdmin\Modules\File\Service\FileReferenceService::class,
            FileAdministration::class => FileAdministrationService::class,
            FileUploads::class => FileUploadService::class,
            FileStorage::class => StorageService::class,
            FileReferences::class => FileService::class,
            FileDerivatives::class => FileDerivativeService::class,
            StorageConfiguration::class => StorageConfigurationService::class,
        ];
    }
}
