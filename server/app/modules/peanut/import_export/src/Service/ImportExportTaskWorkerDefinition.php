<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\ImportExport\Service;

use PeanutAdmin\Modules\Task\Contract\TaskWorkerDefinition;
use PeanutAdmin\Modules\ImportExport\Infrastructure\Authorization\AdminAsyncAuthorization;
use PeanutAdmin\Modules\ImportExport\Engine\Application\ImportExportService;
use PeanutAdmin\Modules\ImportExport\Engine\Execution\ImportExportTaskHandler;
use PeanutAdmin\Kernel\Async\VerifiedJobEnvelope;
use PeanutAdmin\Kernel\Context\AuthorizedOperationContext;
use PeanutAdmin\Modules\Task\Contract\TaskHandler;

/** Import/Export owns its handler and authorization semantics; Task owns execution. */
final readonly class ImportExportTaskWorkerDefinition implements TaskWorkerDefinition
{
    public function __construct(
        private ImportExportTaskHandler $handler,
        private AdminAsyncAuthorization $authorization,
    ) {}

    public function ownerModuleKey(): string
    {
        return 'peanut.import-export';
    }

    public function resourceKey(): string
    {
        return ImportExportService::RESOURCE_KEY;
    }

    public function operation(): string
    {
        return 'create';
    }

    public function handlers(): array
    {
        return [$this->handler];
    }

    public function reauthorize(VerifiedJobEnvelope $envelope): AuthorizedOperationContext
    {
        return $this->authorization->reauthorize($envelope);
    }
}
