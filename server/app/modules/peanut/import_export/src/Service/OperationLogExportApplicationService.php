<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\ImportExport\Service;

use PeanutAdmin\Modules\ImportExport\Contract\Dto\AsyncExportOperation;
use PeanutAdmin\Modules\ImportExport\Contract\Dto\CsvExportOperation;
use app\common\dto\authorization\AdminPrincipal;
use app\common\contract\authorization\AuthorizedOperationFactory;
use PeanutAdmin\Kernel\Auth\TenantContext;

/** Application boundary for the Tenant Admin operation-log export workflow. */
final readonly class OperationLogExportApplicationService
{
    private AuthorizedOperationFactory $authorization;
    private TaskImportExportRuntime $runtime;

    public function __construct(
        AuthorizedOperationFactory $authorization,
        TaskImportExportRuntime $runtime,
    ) {
        $this->authorization = $authorization;
        $this->runtime = $runtime;
    }

    public function submit(
        TenantContext $context,
        AdminPrincipal $principal,
        string $idempotencyKey,
    ): AsyncExportOperation {
        return $this->runtime->commands()->submitCsvExport(
            $this->authorized($context, $principal),
            CsvExportOperation::operationLog($idempotencyKey),
        );
    }

    public function operation(
        TenantContext $context,
        AdminPrincipal $principal,
        string $operationKey,
    ): AsyncExportOperation {
        return $this->runtime->queries()->operation(
            $this->authorized($context, $principal),
            $operationKey,
        );
    }

    /** @return array{url:string,filename:string} */
    public function download(
        TenantContext $context,
        AdminPrincipal $principal,
        string $fileKey,
    ): array {
        return $this->runtime->download(
            $this->authorized($context, $principal),
            $fileKey,
        );
    }

    private function authorized(TenantContext $context, AdminPrincipal $principal): \PeanutAdmin\Kernel\Context\AuthorizedOperationContext
    {
        return $this->authorization->authorizedAsyncExport($context, $principal);
    }
}
