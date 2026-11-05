<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\ImportExport\Service;

use app\common\contract\authorization\AdminAuthorizationQuery;
use app\common\dto\authorization\AdminPrincipal;
use app\common\execution\AdminExecutionContext;
use app\common\execution\CurrentExecutionContext;
use PeanutAdmin\Modules\ImportExport\Contract\Dto\AsyncExportOperation;
use PeanutAdmin\Modules\ImportExport\Contract\ImportExportCommands;
use PeanutAdmin\Modules\ImportExport\Contract\ImportExportQueries;
use PeanutAdmin\Modules\ImportExport\Engine\Application\ImportExportException;
use PeanutAdmin\Modules\ImportExport\Engine\Application\ImportExportService;
use PeanutAdmin\Modules\ImportExport\Infrastructure\File\AppFileMediaGateway;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Context\AuthorizationDecision;
use PeanutAdmin\Kernel\Context\AuthorizedOperationContext;

/** 租户后台导入导出管理用例；HTTP、CLI 或其他调用者都必须提供可信租户与人员。 */
final readonly class ImportExportAdminApplicationService
{
    public function __construct(
        private AdminAuthorizationQuery $authorization,
        private ImportExportCommands $commands,
        private ImportExportQueries $queries,
        private AppFileMediaGateway $files,
        private CurrentExecutionContext $executionContext,
    ) {}

    /** @return array{items:list<AsyncExportOperation>,page:int,page_size:int,total:int} */
    public function operations(
        TenantContext $tenant,
        AdminPrincipal $actor,
        string $status,
        int $page,
        int $pageSize,
    ): array {
        return $this->queries->operations(
            $this->authorized($tenant, $actor, 'peanut.import-export.operations.read', 'read'),
            $status,
            $page,
            $pageSize,
        );
    }

    /** @param array<string,string> $mapping */
    public function submitImport(
        TenantContext $tenant,
        AdminPrincipal $actor,
        string $providerKey,
        string $fileKey,
        array $mapping,
        string $idempotencyKey,
    ): AsyncExportOperation {
        return $this->commands->submitImport(
            $this->authorized($tenant, $actor, 'peanut.import-export.operations.create', 'create'),
            $providerKey,
            $fileKey,
            $mapping,
            $idempotencyKey,
        );
    }

    public function submitExport(
        TenantContext $tenant,
        AdminPrincipal $actor,
        string $providerKey,
        string $idempotencyKey,
    ): AsyncExportOperation {
        return $this->commands->submitExport(
            $this->authorized($tenant, $actor, 'peanut.import-export.operations.create', 'create'),
            $providerKey,
            $idempotencyKey,
        );
    }

    public function cancel(
        TenantContext $tenant,
        AdminPrincipal $actor,
        string $operationKey,
        int $revision,
    ): AsyncExportOperation {
        return $this->commands->cancel(
            $this->authorized($tenant, $actor, 'peanut.import-export.operations.cancel', 'cancel'),
            $operationKey,
            $revision,
        );
    }

    /** @return array{url:string,filename:string} */
    public function download(
        TenantContext $tenant,
        AdminPrincipal $actor,
        string $fileKey,
    ): array {
        $context = $this->authorized($tenant, $actor, 'peanut.import-export.operations.read', 'read');
        $this->queries->resultFile($context, $fileKey);
        return $this->files->download($context, $fileKey);
    }

    /** 权限执行点：permission/resource/operation 由具体管理用例固定，Controller 不能传入通行证。 */
    private function authorized(
        TenantContext $tenant,
        AdminPrincipal $actor,
        string $permission,
        string $operation,
    ): AuthorizedOperationContext {
        $execution = $this->executionContext->current();
        $currentTenantId = $execution?->tenantId();
        if ($currentTenantId !== null && $currentTenantId !== $tenant->tenantId) {
            throw ImportExportException::denied();
        }
        // 可信直接调用仍由授权服务校验显式身份，已有后台执行身份时还须与它一致。
        if ($execution instanceof AdminExecutionContext
            && ($execution->tenant->accountId !== $tenant->accountId
                || $execution->tenant->memberId !== $tenant->memberId
                || $execution->tenant->authorizationRevision !== $tenant->authorizationRevision)
        ) {
            throw ImportExportException::denied();
        }
        if (!$this->authorization->decide($tenant, $actor, $permission)->allowed) {
            throw ImportExportException::denied();
        }
        return AuthorizedOperationContext::fromDecision(AuthorizationDecision::allow(
            $tenant,
            ImportExportService::RESOURCE_KEY,
            $operation,
            [],
            hash('sha256', implode("\0", [
                (string) $tenant->tenantId,
                (string) $tenant->memberId,
                (string) $tenant->authorizationRevision,
                $tenant->requestId,
                $permission,
                $operation,
            ])),
        ));
    }
}
