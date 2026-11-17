<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Ops\Service;

use PeanutAdmin\Modules\Ops\Infrastructure\ApplicationRuntimeStatusProvider;
use app\common\services\audit\AuditContractHost;
use PeanutAdmin\Kernel\Audit\AuditOutcome;
use PeanutAdmin\Kernel\Context\PlatformContext;
use PeanutAdmin\Modules\Ops\Domain\Maintenance\MaintenanceService;
use PeanutAdmin\Modules\Ops\Domain\Status\OpsStatusService;
use PeanutAdmin\Modules\Ops\Domain\Task\OpsTaskService;
use PeanutAdmin\Modules\Ops\Domain\Logs\RuntimeLogQuery;
use PeanutAdmin\Modules\Ops\Domain\Application\OpsConsoleException;
use PeanutAdmin\Modules\Ops\Domain\Application\PlatformPermissionChecker;
use PeanutAdmin\Modules\Ops\Domain\Package;
use app\platform\services\provider\PlatformProviderQualificationService;

/** Container-owned application boundary for all Platform Ops use cases. */
final readonly class PlatformOpsApplicationService
{
    public function __construct(
        private OpsStatusService $status,
        private ApplicationRuntimeStatusProvider $runtimeStatus,
        private PlatformProviderQualificationService $providerQualifications,
        private MaintenanceService $maintenance,
        private PlatformDiagnosticBundleService $diagnostics,
        private AuditContractHost $audit,
        private OpsTaskService $tasks,
        private PlatformUpgradeExecutionService $upgrades,
        private PlatformModuleOperationExecutionService $moduleOperations,
        private PlatformBackupCenterService $backups,
        private PlatformPermissionChecker $permissions,
    ) {}

    /** @return array<string,mixed> */
    public function status(PlatformContext $context): array
    {
        return $this->status->read($context)->toPublicArray();
    }

    /** @return array<string,mixed> */
    public function upgradeReadiness(PlatformContext $context): array
    {
        return $this->runtimeStatus->upgradeReadiness($context);
    }

    /** @return array<string,mixed> */
    public function providers(PlatformContext $context, int $page = 1, int $pageSize = 20): array
    {
        return $this->providerQualifications->snapshot($context, $page, $pageSize);
    }

    /** @return array<string,mixed>|null */
    public function maintenance(PlatformContext $context): ?array
    {
        return $this->maintenance->current($context)?->toPublicArray();
    }

    /** @return array<string,mixed> */
    public function logs(PlatformContext $context, string $source, string $severity, int $pageSize, ?string $cursor = null): array
    {
        if ($source !== 'platform.audit') {
            throw OpsConsoleException::invalid();
        }
        try {
            $query = new RuntimeLogQuery($source, $severity, $cursor, $pageSize);
        } catch (\InvalidArgumentException) {
            throw OpsConsoleException::invalid();
        }
        return $this->diagnostics->logs($context, $query);
    }

    /** @return array<string,mixed> */
    public function scheduleMaintenance(
        PlatformContext $context,
        string $reasonKey,
        string $startsAt,
        string $endsAt,
        int $revision,
        string $idempotencyKey,
    ): array {
        return $this->maintenance
            ->schedule($context, $reasonKey, $startsAt, $endsAt, $revision, $idempotencyKey)
            ->toPublicArray();
    }

    /** @return array<string,mixed> */
    public function closeMaintenance(
        PlatformContext $context,
        string $maintenanceKey,
        int $revision,
        string $idempotencyKey,
    ): array {
        return $this->maintenance
            ->close($context, $maintenanceKey, $revision, $idempotencyKey)
            ->toPublicArray();
    }

    /** @return array{json:string,sha256:string,filename:string,bytes:int} */
    public function diagnostics(PlatformContext $context, int $windowMinutes, string $requestId): array
    {
        $artifact = $this->diagnostics->create($context, $windowMinutes);
        $this->audit->recordPlatform(
            'platform.ops.diagnostics.downloaded',
            'platform.ops.logs.read',
            $requestId,
            $context->operatorId,
            $context->accountId,
            [
                'artifact_sha256' => $artifact['sha256'],
                'artifact_bytes' => $artifact['bytes'],
                'window_minutes' => $windowMinutes,
            ],
            AuditOutcome::Success,
            null,
        );
        return $artifact;
    }

    /** @return array<string,mixed> */
    public function submitBackup(
        PlatformContext $context,
        string $providerKey,
        string $idempotencyKey,
    ): array {
        if (!$this->permissions->allows($context, Package::BACKUP_PERMISSION)) {
            throw OpsConsoleException::denied();
        }
        $this->runtimeStatus->runtimeCommit();
        return $this->tasks
            ->submitBackup($context, $providerKey, $idempotencyKey)
            ->toPublicArray();
    }

    /** @return array<string,mixed> */
    public function submitRestore(
        PlatformContext $context,
        string $providerKey,
        string $backupReferenceKey,
        string $targetKey,
        string $idempotencyKey,
    ): array {
        if (!$this->permissions->allows($context, Package::RESTORE_PERMISSION)) {
            throw OpsConsoleException::denied();
        }
        $this->runtimeStatus->runtimeCommit();
        return $this->tasks
            ->submitRestore($context, $providerKey, $backupReferenceKey, $targetKey, $idempotencyKey)
            ->toPublicArray();
    }

    /** @return array<string,mixed> */
    public function submitUpgrade(PlatformContext $context, string $idempotencyKey): array
    {
        return $this->upgrades->submit($context, $idempotencyKey);
    }

    /** @return array<string,mixed> */
    public function submitModuleOperation(
        PlatformContext $context,
        string $requestKey,
        string $idempotencyKey,
    ): array {
        return $this->moduleOperations
            ->submit($context, $requestKey, $idempotencyKey);
    }

    /** @return array<string,mixed> */
    public function moduleOperations(PlatformContext $context): array
    {
        return $this->moduleOperations->snapshot($context);
    }

    /** @return array<string,mixed> */
    public function upgrades(PlatformContext $context): array
    {
        return $this->upgrades->snapshot($context);
    }

    /** @return array<string,mixed> */
    public function backups(PlatformContext $context, int $page = 1, int $pageSize = 20): array
    {
        return $this->backups->snapshot($context, $this->runtimeStatus->snapshot($context)->commit, $page, $pageSize);
    }

    /** @return array<string,mixed> */
    public function task(PlatformContext $context, string $taskKey): array
    {
        $module = $this->moduleOperations
            ->taskIfModuleOperation($context, $taskKey);
        $upgrade = $this->upgrades
            ->taskIfUpgrade($context, $taskKey);
        return $module ?? $upgrade ?? $this->tasks
            ->task($context, $taskKey)
            ->toPublicArray();
    }
}
