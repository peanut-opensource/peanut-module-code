<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\ImportExport\Service;

use PeanutAdmin\Modules\Task\Contract\TaskWorkerDefinition;
use PeanutAdmin\Modules\ImportExport\Infrastructure\Authorization\AdminAsyncAuthorization;
use PeanutAdmin\Modules\ImportExport\Engine\Application\ImportExportException;
use PeanutAdmin\Modules\ImportExport\Engine\Application\ImportExportService;
use PeanutAdmin\Modules\ImportExport\Engine\Execution\ImportExportTaskHandler;
use PeanutAdmin\Kernel\Async\VerifiedJobEnvelope;
use PeanutAdmin\Kernel\Context\AuthorizedOperationContext;
use PeanutAdmin\Modules\Task\Contract\TaskHandler;
use PeanutAdmin\Modules\Task\Contract\TaskSubmission;
use PeanutAdmin\Modules\Task\Contract\TaskSubmissionProvider;

/** Import/Export owns its handler and authorization semantics; Task owns execution. */
final readonly class ImportExportTaskWorkerDefinition implements TaskWorkerDefinition, TaskSubmissionProvider
{
    public function __construct(
        private ImportExportTaskHandler $handler,
        private AdminAsyncAuthorization $authorization,
    ) {}

    public function taskType(): string
    {
        return ImportExportService::TASK_TYPE;
    }

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

    public function build(AuthorizedOperationContext $context, array $input): TaskSubmission
    {
        if (array_keys($input) !== ['operation_key'] || !is_string($input['operation_key'])) {
            throw ImportExportException::invalid();
        }
        ImportExportService::assertOperationKey($input['operation_key']);
        return new TaskSubmission('peanut.import-export.execute', ['operation_key' => $input['operation_key']], 3);
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
