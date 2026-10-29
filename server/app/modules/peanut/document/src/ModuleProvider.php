<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Document;

use PeanutAdmin\Modules\Document\Contract\DocumentCommands;
use PeanutAdmin\Modules\Document\Contract\DocumentQueries;
use PeanutAdmin\Modules\Document\Service\DocumentService;

final class ModuleProvider implements \PeanutAdmin\Kernel\Module\ModuleProvider, \PeanutAdmin\Modules\File\Contract\FileDocumentReferenceContributor
{
    public function moduleKey(): string
    {
        return 'peanut.document';
    }
    public function bindings(): array
    {
        return [DocumentCommands::class => DocumentService::class,DocumentQueries::class => DocumentService::class,
            \PeanutAdmin\Modules\Document\Contract\DocumentCollaborativeWrites::class => DocumentService::class];
    }
    public function fileDocumentReferencePolicy(): string
    {
        return DocumentService::class;
    }
}
