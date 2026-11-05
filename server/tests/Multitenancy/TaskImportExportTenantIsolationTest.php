<?php

declare(strict_types=1);

use app\common\services\authorization\AdminAuthorizationService;
use PeanutAdmin\Modules\ImportExport\Service\TaskImportExportRuntime;
use PeanutAdmin\Modules\ImportExport\Infrastructure\Authorization\AdminAsyncAuthorization;
use PeanutAdmin\Modules\ImportExport\Contract\Dto\CsvExportOperation;
use PeanutAdmin\Modules\File\Service\Storage\StorageService;
use PeanutAdmin\Modules\ImportExport\Engine\Application\ImportExportService;
use PeanutAdmin\Kernel\Async\VerifiedJobEnvelope;
use PeanutAdmin\Kernel\Context\RequestedTargetSet;
use PeanutAdmin\Kernel\Context\AuthorizedOperationContext;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Modules\Task\Service\TaskWorkerDefinitionRegistry;
use app\common\execution\AdminExecutionContext;
use app\common\execution\ExecutionContextStore;

function expectAsyncTenant(bool $condition, string $message): void
{
    $GLOBALS['taskImportExportChecks'] = ($GLOBALS['taskImportExportChecks'] ?? 0) + 1;
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function submitOperationLogExport(TaskImportExportRuntime $runtime, callable $guard, AuthorizedOperationContext $context, string $idempotencyKey): object
{
    $guard();
    return $runtime->commands()->submitCsvExport(
        $context,
        CsvExportOperation::operationLog($idempotencyKey),
    );
}

function runAsyncTenant(
    TaskImportExportRuntime $runtime,
    callable $guard,
    ExecutionContextStore $contexts,
    int $tenantId,
    string $workerId,
): int {
    $guard();
    $processed = $runtime->runTenant($tenantId, $workerId);
    expectAsyncTenant($contexts->isEmpty(), 'worker execution context leaked after ' . $workerId);
    return $processed;
}

/**
 * Retained real async assertions against the current concrete qualification adapter.
 * ImportExportWorkerRuntime itself remains the runTenant-only public contract. All contexts
 * must come from the host's existing authenticated sessions. This body is only
 * usable inside a native protected qualification boundary; the CLI stops below.
 * It never installs Modules, creates identities, replaces secrets or clears DBs.
 */
function taskImportExportInstalledAssertions(
    think\App $app,
    PDO $pdo,
    TenantContext $alphaTenant,
    TenantContext $betaTenant,
    TenantContext $noExportTenant,
    TenantContext $auditFailureTenant,
    int $alphaRoleId,
    array $expectedDatabaseIdentity,
    callable $guard,
    callable $moduleSelection,
): void {
    $GLOBALS['taskImportExportChecks'] = 0;
    // Only these existing qualification DML operations use this same native transaction.
    $write = static function (PDOStatement|string $sql, array $parameters = []) use ($pdo, $guard): void {
        \think\facade\Db::transaction(static function () use ($pdo, $guard, $sql, $parameters): void {
            $guard(true);
            $statement = $sql instanceof PDOStatement ? $sql : $pdo->prepare($sql);
            $guard(true);
            $statement->execute($parameters);
            $guard(true);
        });
    };
    $installation = $app->make(app\common\services\installation\InstallationExecutionHost::class);
    $status = $installation->status();
    expectAsyncTenant(($status['state'] ?? null) === 'installed' && ($status['deployment_mode'] ?? null) === 'multi-tenant', 'healthy installed multi-tenant Code is required');
    expectAsyncTenant(app\common\services\installation\InstallationExecutionHost::migrationDatabaseIdentity($pdo) === $expectedDatabaseIdentity, 'native database identity differs from the protected admission');
    $tenantId = $alphaTenant->tenantId;
    $otherTenantId = $betaTenant->tenantId;
    $accountId = $alphaTenant->accountId;
    $memberId = $alphaTenant->memberId;
    expectAsyncTenant($tenantId > 0 && $otherTenantId > 0 && $tenantId !== $otherTenantId, 'two distinct installed Tenants are required');
    expectAsyncTenant($noExportTenant->tenantId === $tenantId && $noExportTenant->memberId !== $memberId, 'distinct existing permission-less member is required');
    expectAsyncTenant($auditFailureTenant->tenantId === $tenantId && $auditFailureTenant->accountId === $accountId && $auditFailureTenant->memberId === $memberId, 'audit failure session must belong to the same installed principal');
    foreach ([$alphaTenant, $betaTenant, $noExportTenant, $auditFailureTenant] as $principal) {
        $query = $pdo->prepare("SELECT COUNT(*) FROM pa_tenant_member m JOIN pa_tenant t ON t.id=m.tenant_id JOIN pa_account a ON a.id=m.account_id WHERE m.id=? AND m.tenant_id=? AND m.account_id=? AND m.status='active' AND t.status='active' AND a.status='active'");
        $query->execute([$principal->memberId, $principal->tenantId, $principal->accountId]);
        expectAsyncTenant((int) $query->fetchColumn() === 1, 'host session does not match the installed database');
    }
    $runId = strtolower(bin2hex(random_bytes(6)));
    $exportPermission = (int) $pdo->query("SELECT id FROM pa_permission WHERE `key`='peanut.import-export.operation-log.export' AND status='active'")->fetchColumn();
    expectAsyncTenant($exportPermission > 0 && $alphaRoleId > 0, 'existing native export Permission and Role are required');
    $grant = $pdo->prepare('SELECT * FROM pa_role_permission WHERE tenant_id=? AND role_id=? AND permission_id=?');
    $grant->execute([$tenantId, $alphaRoleId, $exportPermission]);
    $originalGrant = $grant->fetch(PDO::FETCH_ASSOC);
    expectAsyncTenant(is_array($originalGrant), 'existing export grant is required');
    $role = $pdo->prepare("SELECT COUNT(*) FROM pa_member_role mr JOIN pa_role r ON r.id=mr.role_id AND r.tenant_id=mr.tenant_id WHERE mr.tenant_id=? AND mr.tenant_member_id=? AND mr.role_id=? AND r.status='active'");
    $role->execute([$tenantId, $memberId, $alphaRoleId]);
    expectAsyncTenant((int) $role->fetchColumn() === 1, 'export grant Role is not assigned to the installed principal');
    $moduleStates = $app->make(\PeanutAdmin\Modules\Identity\Contract\TenantModuleStateQueries::class);
    foreach ([$tenantId, $otherTenantId] as $selectedTenantId) {
        foreach (['peanut.task', 'peanut.import-export'] as $moduleKey) {
            expectAsyncTenant($moduleStates->acceptsNewOperations($selectedTenantId, $moduleKey), 'current license must admit installed Task and Import/Export Modules');
        }
    }
    $assertModuleSelection = static function (string $moduleKey, bool $enabled) use ($app, $moduleStates, $tenantId, $otherTenantId, $grant, $alphaRoleId, $exportPermission, $originalGrant): void {
        expectAsyncTenant(
            $moduleStates->acceptsNewOperations($tenantId, $moduleKey) === $enabled,
            'alpha license module availability differs from the intended selection',
        );
        expectAsyncTenant(
            $moduleStates->acceptsNewOperations($otherTenantId, $moduleKey),
            'alpha license change affected beta availability',
        );
        $grant->execute([$tenantId, $alphaRoleId, $exportPermission]);
        expectAsyncTenant(
            $grant->fetch(PDO::FETCH_ASSOC) === $originalGrant,
            'license qualification changed the native RBAC grant',
        );
        $moduleGuard = new \PeanutAdmin\Kernel\Module\ModuleGuard($app->make(\PeanutAdmin\Kernel\Module\ModuleRuntimeRepository::class));
        if ($enabled) {
            $moduleGuard->assertTenant($tenantId, $moduleKey, new DateTimeImmutable());
            return;
        }
        try {
            $moduleGuard->assertTenant($tenantId, $moduleKey, new DateTimeImmutable());
            throw new RuntimeException('native module stop did not reject alpha');
        } catch (\PeanutAdmin\Kernel\Module\ModuleException $error) {
            expectAsyncTenant($error->errorCode === 'MODULE_TENANT_DISABLED', 'module stop failed for a different reason');
        }
    };
    $pending = $pdo->prepare("SELECT COUNT(*) FROM pa_task_job WHERE tenant_id IN (?,?) AND status IN ('queued','running')");
    $pending->execute([$tenantId, $otherTenantId]);
    expectAsyncTenant((int) $pending->fetchColumn() === 0, 'existing Tenant work must not be claimed by qualification workers');
    $tenantOriginal = $pdo->prepare('SELECT suspended_at FROM pa_tenant WHERE id=?');
    $tenantOriginal->execute([$tenantId]);
    $originalSuspendedAt = $tenantOriginal->fetchColumn();
    // Reject unsuitable existing principals before the first qualification write.
    // Native principal roles are active memberships; native export authorization
    // also verifies the current session and effective Module/Permission state.
    $authorization = $app->make(AdminAuthorizationService::class);
    $alphaPrincipal = $authorization->principal($alphaTenant);
    expectAsyncTenant(!$alphaPrincipal->root, 'revocation assertion requires a non-Root installed principal');
    $activeRoleIds = array_column($alphaPrincipal->roles, 'id');
    expectAsyncTenant($activeRoleIds !== [], 'native principal has no active Roles');
    $sources = $pdo->prepare('SELECT role_id FROM pa_role_permission WHERE tenant_id=? AND permission_id=? AND role_id IN (' . implode(',', array_fill(0, count($activeRoleIds), '?')) . ')');
    $sources->execute([$tenantId, $exportPermission, ...$activeRoleIds]);
    $sourceRoles = array_map('intval', $sources->fetchAll(PDO::FETCH_COLUMN));
    expectAsyncTenant($sourceRoles === [$alphaRoleId], 'specified Role must be the sole effective export grant source');
    $alpha = $authorization->authorizedAsyncExport($alphaTenant, $alphaPrincipal);
    $beta = $authorization->authorizedAsyncExport($betaTenant, $authorization->principal($betaTenant));

    $auditFailureRequest = $auditFailureTenant->requestId;
    expectAsyncTenant(trim($auditFailureRequest) !== '', 'independent host audit failure request ID is required');
    $auditCollation = $pdo->query("SELECT COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='pa_tenant_audit_event' AND COLUMN_NAME='request_id'")->fetchColumn();
    expectAsyncTenant(is_string($auditCollation) && preg_match('/^utf8mb4_[a-z0-9_]+$/D', $auditCollation) === 1, 'native audit request ID collation is unavailable');
    $sameRequest = $pdo->prepare("SELECT CONVERT(? USING utf8mb4) COLLATE {$auditCollation} = CONVERT(? USING utf8mb4)");
    foreach ([$alphaTenant, $betaTenant, $noExportTenant] as $otherContext) {
        $sameRequest->execute([$auditFailureRequest, $otherContext->requestId]);
        expectAsyncTenant((int) $sameRequest->fetchColumn() === 0, 'audit failure request ID must differ from every other input context');
    }
    // The later CHECK applies to the whole table, across all Tenants. Reject any
    // historical use under that same column collation; never remove old events.
    $usedRequest = $pdo->prepare('SELECT COUNT(*) FROM pa_tenant_audit_event WHERE request_id=?');
    $usedRequest->execute([$auditFailureRequest]);
    expectAsyncTenant((int) $usedRequest->fetchColumn() === 0, 'audit failure host request ID has already been used');
    $fileCountBefore = (int) $pdo->query('SELECT COUNT(*) FROM pa_file_object')->fetchColumn();
    $alphaMarker = 'alpha-only-' . $runId;
    $betaMarker = 'beta-only-' . $runId;
    $operationLogs = $pdo->prepare('INSERT INTO pa_operation_log (tenant_id,admin_id,username,uri,method,params,create_time) VALUES (?,?,?,?,?,?,UNIX_TIMESTAMP())');
    foreach ([[$alphaTenant, $alphaMarker], [$betaTenant, $betaMarker]] as [$principal, $marker]) {
        $guard();
        $write($operationLogs, [$principal->tenantId, $principal->memberId, 'p3-' . $runId, 'p3/' . $runId, 'POST', json_encode(['marker' => $marker], JSON_THROW_ON_ERROR)]);
    }
    $workerDefinitions = $app->make(TaskWorkerDefinitionRegistry::class)->resolve($app);
    $handlerKeys = [];
    foreach ($workerDefinitions as $definition) {
        foreach ($definition->handlers() as $handler) {
            $handlerKeys[] = $handler->key();
        }
    }
    expectAsyncTenant(in_array('peanut.import-export.execute', $handlerKeys, true), 'unified worker lost Import/Export handler');
    expectAsyncTenant(in_array('notification.inbox', $handlerKeys, true), 'unified worker lost Notification inbox handler');
    expectAsyncTenant(in_array('notification.sms', $handlerKeys, true), 'unified worker lost Notification SMS handler');
    try {
        $authorization->authorizedAsyncExport($noExportTenant, $authorization->principal($noExportTenant));
        throw new RuntimeException('native permission-less async submission succeeded');
    } catch (DomainException $exception) {
        expectAsyncTenant($exception->getMessage() === 'ASYNC_EXPORT_PERMISSION_DENIED', 'native permission denial shape changed');
    }

    $revalidator = $app->make(AdminAsyncAuthorization::class);
    $validEnvelope = new VerifiedJobEnvelope(
        $tenantId,
        $accountId,
        $memberId,
        ImportExportService::RESOURCE_KEY,
        'create',
        [],
        'envelope-' . $runId,
        'trace-' . $runId,
    );
    $reauthorized = $revalidator->reauthorize($validEnvelope);
    expectAsyncTenant($reauthorized->resourceKey === ImportExportService::RESOURCE_KEY, 'valid async resource changed');
    expectAsyncTenant($reauthorized->operation === 'create' && $reauthorized->targets === [], 'valid async operation changed');
    foreach ([
        new VerifiedJobEnvelope($tenantId, $accountId, $memberId, 'wrong.resource', 'create', [], 'bad-resource', 'trace'),
        new VerifiedJobEnvelope($tenantId, $accountId, $memberId, ImportExportService::RESOURCE_KEY, 'delete', [], 'bad-operation', 'trace'),
        new VerifiedJobEnvelope($tenantId, $accountId, $memberId, ImportExportService::RESOURCE_KEY, 'create', [new RequestedTargetSet('file', ['1'])], 'bad-targets', 'trace'),
    ] as $invalidEnvelope) {
        try {
            $revalidator->reauthorize($invalidEnvelope);
            throw new RuntimeException('invalid async envelope was accepted');
        } catch (PeanutAdmin\Kernel\Auth\AuthException) {
            // Expected: the signed envelope fields are part of the authorization contract.
        }
    }

    $runtime = $app->make(TaskImportExportRuntime::class);
    $executionContexts = $app->make(ExecutionContextStore::class);
    expectAsyncTenant($executionContexts->isEmpty(), 'worker execution context was not initially empty');
    $taskDisabled = submitOperationLogExport($runtime, $guard, $alpha, 'task-disabled-' . $runId);
    $guard();
    $moduleSelection('peanut.task', false);
    $assertModuleSelection('peanut.task', false);
    expectAsyncTenant(runAsyncTenant($runtime, $guard, $executionContexts, $tenantId, 'installed-task-disabled-' . $runId) === 1, 'disabled Task Module job was not examined');
    expectAsyncTenant($pdo->query("SELECT CONCAT(status,':',last_error_code) FROM pa_task_job WHERE job_key = " . $pdo->quote((string) $taskDisabled->taskJobKey))->fetchColumn() === 'dead:TASK_PERMISSION_DENIED', 'Task module revocation did not fail at the native signed authorization fence');
    $guard();
    [$alphaTenant, $auditFailureTenant] = $moduleSelection('peanut.task', true);
    $assertModuleSelection('peanut.task', true);
    $alpha = $authorization->authorizedAsyncExport($alphaTenant, $authorization->principal($alphaTenant));

    $importExportDisabled = submitOperationLogExport($runtime, $guard, $alpha, 'import-export-disabled-' . $runId);
    $guard();
    $moduleSelection('peanut.import-export', false);
    $assertModuleSelection('peanut.import-export', false);
    expectAsyncTenant(runAsyncTenant($runtime, $guard, $executionContexts, $tenantId, 'installed-import-export-disabled-' . $runId) === 1, 'disabled Import/Export Module job was not examined');
    expectAsyncTenant($pdo->query("SELECT CONCAT(status,':',last_error_code) FROM pa_task_job WHERE job_key = " . $pdo->quote((string) $importExportDisabled->taskJobKey))->fetchColumn() === 'dead:TASK_PERMISSION_DENIED', 'Import/Export module revocation did not fail at the native signed authorization fence');
    $guard();
    [$alphaTenant, $auditFailureTenant] = $moduleSelection('peanut.import-export', true);
    $assertModuleSelection('peanut.import-export', true);
    $alpha = $authorization->authorizedAsyncExport($alphaTenant, $authorization->principal($alphaTenant));

    $operationCountBeforeIdempotency = (int) $pdo->query('SELECT COUNT(*) FROM pa_import_export_operation')->fetchColumn();
    $jobCountBeforeIdempotency = (int) $pdo->query('SELECT COUNT(*) FROM pa_task_job')->fetchColumn();
    $operation = submitOperationLogExport($runtime, $guard, $alpha, 'alpha-idempotency-' . $runId);
    $duplicate = submitOperationLogExport($runtime, $guard, $alpha, 'alpha-idempotency-' . $runId);
    expectAsyncTenant($duplicate->operationKey === $operation->operationKey, 'idempotent submission created a second operation');
    expectAsyncTenant((int) $pdo->query('SELECT COUNT(*) FROM pa_import_export_operation')->fetchColumn() === $operationCountBeforeIdempotency + 1, 'idempotent operation count changed');
    expectAsyncTenant((int) $pdo->query('SELECT COUNT(*) FROM pa_task_job')->fetchColumn() === $jobCountBeforeIdempotency + 1, 'idempotent job count changed');

    $operationCountAfterIdempotency = (int) $pdo->query('SELECT COUNT(*) FROM pa_import_export_operation')->fetchColumn();
    $jobCountAfterIdempotency = (int) $pdo->query('SELECT COUNT(*) FROM pa_task_job')->fetchColumn();
    // 仅让本次新提交的审计写入失败，不能让既有成功记录阻止安装故障注入约束。
    $auditFailureContext = $authorization->authorizedAsyncExport(
        $auditFailureTenant,
        $authorization->principal($auditFailureTenant),
    );
    $auditFailureConstraint = 'chk_p3_audit_' . $runId;
    $guard();
    $pdo->exec(
        "ALTER TABLE pa_tenant_audit_event ADD CONSTRAINT {$auditFailureConstraint} "
        . "CHECK (event_type <> 'tenant.import_export.submitted' OR request_id <> " . $pdo->quote($auditFailureRequest) . ")",
    );
    $guard();
    try {
        submitOperationLogExport($runtime, $guard, $auditFailureContext, 'atomic-failure-' . $runId);
        throw new RuntimeException('atomic failure injection unexpectedly committed');
    } catch (Throwable $exception) {
        expectAsyncTenant(
            str_contains($exception->getMessage(), $auditFailureConstraint),
            'deterministic audit constraint failure was not reached',
        );
    }
    // Only remove the known constraint after its expected failure was proven.
    // Unexpected failure retains the scene for the native recovery owner.
    $guard();
    $pdo->exec("ALTER TABLE pa_tenant_audit_event DROP CHECK {$auditFailureConstraint}");
    $guard();
    expectAsyncTenant((int) $pdo->query('SELECT COUNT(*) FROM pa_import_export_operation')->fetchColumn() === $operationCountAfterIdempotency, 'failed submission left an operation');
    expectAsyncTenant((int) $pdo->query('SELECT COUNT(*) FROM pa_task_job')->fetchColumn() === $jobCountAfterIdempotency, 'failed submission left a job');

    $jobKey = (string) $operation->taskJobKey;
    $envelope = (string) $pdo->query("SELECT trusted_envelope FROM pa_task_job WHERE job_key = " . $pdo->quote($jobKey))->fetchColumn();
    $forged = str_replace('"tenant_id":' . $tenantId . ',', '"tenant_id":' . $otherTenantId . ',', $envelope);
    expectAsyncTenant($forged !== $envelope, 'signed envelope Tenant field was not found for tampering');
    $guard();
    $write('UPDATE pa_task_job SET trusted_envelope = ? WHERE job_key = ?', [$forged, $jobKey]);
    expectAsyncTenant(runAsyncTenant($runtime, $guard, $executionContexts, $tenantId, 'installed-forged-' . $runId) === 1, 'forged job was not claimed');
    expectAsyncTenant($pdo->query("SELECT status FROM pa_task_job WHERE job_key = " . $pdo->quote($jobKey))->fetchColumn() === 'dead', 'forged envelope did not fail closed');
    expectAsyncTenant((int) $pdo->query('SELECT COUNT(*) FROM pa_file_object')->fetchColumn() === $fileCountBefore, 'forged envelope produced an artifact');

    $suspended = submitOperationLogExport($runtime, $guard, $alpha, 'suspended-' . $runId);
    $guard();
    $write("UPDATE pa_tenant SET status = 'suspended', suspended_at = UTC_TIMESTAMP(3) WHERE id = {$tenantId}");
    expectAsyncTenant(runAsyncTenant($runtime, $guard, $executionContexts, $tenantId, 'installed-suspended-' . $runId) === 1, 'suspended Tenant job was not examined');
    expectAsyncTenant($pdo->query("SELECT status FROM pa_task_job WHERE job_key = " . $pdo->quote((string) $suspended->taskJobKey))->fetchColumn() === 'dead', 'suspended Tenant job executed');
    $guard();
    $write("UPDATE pa_tenant SET status='active', suspended_at=? WHERE id=?", [$originalSuspendedAt, $tenantId]);

    $revoked = submitOperationLogExport($runtime, $guard, $alpha, 'revoked-' . $runId);
    $guard();
    $write("DELETE FROM pa_role_permission WHERE tenant_id = {$tenantId} AND role_id = {$alphaRoleId} AND permission_id = {$exportPermission}");
    expectAsyncTenant(runAsyncTenant($runtime, $guard, $executionContexts, $tenantId, 'installed-revoked-' . $runId) === 1, 'revoked job was not examined');
    expectAsyncTenant($pdo->query("SELECT status FROM pa_task_job WHERE job_key = " . $pdo->quote((string) $revoked->taskJobKey))->fetchColumn() === 'dead', 'revoked native Permission still executed');
    $grantColumns = array_keys($originalGrant);
    foreach ($grantColumns as $column) {
        expectAsyncTenant(preg_match('/^[a-z_]+$/D', $column) === 1, 'unexpected native grant column');
    }
    $guard();
    $write('INSERT INTO pa_role_permission (`' . implode('`,`', $grantColumns) . '`) VALUES (' . implode(',', array_fill(0, count($grantColumns), '?')) . ')', array_values($originalGrant));

    $success = submitOperationLogExport($runtime, $guard, $alpha, 'success-' . $runId);
    expectAsyncTenant(runAsyncTenant($runtime, $guard, $executionContexts, $tenantId, 'installed-success-' . $runId) === 1, 'successful job was not processed');
    $completed = $runtime->operation($alpha, $success->operationKey);
    $jobFailure = $pdo->query('SELECT status,last_error_code FROM pa_task_job WHERE job_key = ' . $pdo->quote((string) $success->taskJobKey))->fetch(PDO::FETCH_ASSOC);
    if ($completed->status !== 'succeeded') {
        foreach ($app->log->getLog() as $record) {
            $properties = get_object_vars($record);
            foreach ($properties as $value) {
                if (is_array($value) && ($value['event'] ?? '') === 'import_export.unexpected_failure') {
                    echo 'SAFE_EXPORT_DIAGNOSTIC ' . json_encode($value, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
                }
            }
        }
    }
    expectAsyncTenant(
        $completed->status === 'succeeded' && $completed->resultFileKey !== null,
        'successful export did not publish a result: operation=' . $completed->status
        . ', operation_error=' . ($completed->lastErrorCode ?? 'none')
        . ', job=' . json_encode($jobFailure, JSON_THROW_ON_ERROR),
    );
    $downloadResult = static function (AuthorizedOperationContext $context, string $fileKey) use ($authorization, $executionContexts, $runtime): array {
        $actor = $context->tenantContext;
        return $executionContexts->run(
            new AdminExecutionContext($actor, 'import-export.operation-log.download', $authorization->principal($actor)->toArray()),
            static fn(): array => $runtime->download($context, $fileKey),
        );
    };
    $guard();
    $download = $downloadResult($alpha, $completed->resultFileKey);
    expectAsyncTenant(isset($download['url']) && is_string($download['url']) && str_contains($download['url'], '/api/storage/delivery?'), 'Tenant-gated CSV URL is missing');
    expectAsyncTenant(!str_contains((string) $download['url'], '/public/'), 'CSV was exposed below public/');
    parse_str((string) parse_url((string) $download['url'], PHP_URL_QUERY), $deliveryQuery);
    $storage = $app->make(StorageService::class);
    $guard();
    $activeDelivery = $storage->authorizedDownload(
        (int) ($deliveryQuery['tenant_id'] ?? 0),
        (string) ($deliveryQuery['file_key'] ?? ''),
        (string) ($deliveryQuery['token'] ?? ''),
    );
    expectAsyncTenant(
        is_file($activeDelivery['path']) && str_contains((string) file_get_contents($activeDelivery['path']), $alphaMarker)
            && !str_contains((string) file_get_contents($activeDelivery['path']), $betaMarker),
        'active Tenant file delivery did not return its ready object',
    );
    try {
        $guard();
        $storage->authorizedDownload((int) $deliveryQuery['tenant_id'], (string) $deliveryQuery['file_key'], (string) $deliveryQuery['token']);
        throw new RuntimeException('private single-use delivery token was accepted twice');
    } catch (app\common\exception\BusinessException $exception) {
        expectAsyncTenant($exception->getMessage() === '文件链接无效或已过期', 'private replay denial changed');
    }
    // 第二条尚未消费的链接，用来独立验证租户暂停/恢复。
    $guard();
    $secondDownload = $downloadResult($alpha, $completed->resultFileKey);
    parse_str((string) parse_url($secondDownload['url'], PHP_URL_QUERY), $deliveryQuery);
    $guard();
    $write("UPDATE pa_tenant SET status = 'suspended', suspended_at = UTC_TIMESTAMP(3) WHERE id = {$tenantId}");
    try {
        $guard();
        $storage->authorizedDownload(
            (int) $deliveryQuery['tenant_id'],
            (string) $deliveryQuery['file_key'],
            (string) $deliveryQuery['token'],
        );
        throw new RuntimeException('an already-issued file URL survived Tenant suspension');
    } catch (RuntimeException $exception) {
        expectAsyncTenant($exception->getMessage() === '文件不存在或不可用', 'suspended file denial changed');
    }
    $guard();
    $write("UPDATE pa_tenant SET status='active', suspended_at=? WHERE id=?", [$originalSuspendedAt, $tenantId]);
    $guard();
    expectAsyncTenant(
        is_file($storage->authorizedDownload(
            (int) $deliveryQuery['tenant_id'],
            (string) $deliveryQuery['file_key'],
            (string) $deliveryQuery['token'],
        )['path']),
        'reactivation did not restore the still-ready signed file',
    );
    $guard();
    $thirdDownload = $downloadResult($alpha, $completed->resultFileKey);
    parse_str((string) parse_url($thirdDownload['url'], PHP_URL_QUERY), $deliveryQuery);
    $guard();
    $write("UPDATE pa_file_object SET status='archived', archived_at=UTC_TIMESTAMP(3) WHERE tenant_id={$tenantId} AND file_key=?", [(string) $deliveryQuery['file_key']]);
    try {
        $guard();
        $storage->authorizedDownload(
            (int) $deliveryQuery['tenant_id'],
            (string) $deliveryQuery['file_key'],
            (string) $deliveryQuery['token'],
        );
        throw new RuntimeException('reactivation restored an archived object');
    } catch (RuntimeException $exception) {
        expectAsyncTenant($exception->getMessage() === '文件不存在或不可用', 'archived file denial changed');
    }
    try {
        $guard();
        $downloadResult($beta, $completed->resultFileKey);
        throw new RuntimeException('cross-Tenant result download succeeded');
    } catch (Throwable $exception) {
        expectAsyncTenant($exception->getMessage() === 'IMPORT_EXPORT_FILE_UNAVAILABLE', 'cross-Tenant download enumerated ownership');
    }

    // Preserve all actual rows, artifacts, grant/tenant state and failed DDL.
    // Never restore a stale whole-instance backup after writers have reopened.
    echo 'MT03-TASK-IMPORT-EXPORT-TENANT-ISOLATION-001 passed (' . ($GLOBALS['taskImportExportChecks'] ?? 0) . " checks)\n";
}

// These assertions require a separately authorized installed-instance owner.
// Loading this file must not manufacture a qualification frame or initialize data.
if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    fwrite(STDERR, "NOT_RUN: supply an authorized installed-instance harness before invoking these assertions.\n");
    exit(2);
}
