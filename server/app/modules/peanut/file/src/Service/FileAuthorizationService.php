<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\File\Service;

use app\common\contract\authorization\AdminAuthorizationQuery;
use app\common\dto\authorization\AdminPrincipal;
use app\common\exception\BusinessException;
use app\common\execution\AdminExecutionContext;
use app\common\execution\ExecutionContextStore;
use DateTimeImmutable;
use DateTimeZone;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Context\AuthorizedOperationContext;
use PeanutAdmin\Kernel\Module\ModuleGuard;
use PeanutAdmin\Kernel\Module\ModuleInstallationRecord;
use PeanutAdmin\Kernel\Module\ModuleRuntimeRepository;
use PeanutAdmin\Kernel\Module\TenantModuleRecord;
use PeanutAdmin\Modules\File\Value\Storage\StoragePurpose;
use PeanutAdmin\Modules\Identity\Contract\TenantSessionAccess;

/** Live identity/RBAC/Module and contextual policy, never a parallel resource ACL. */
final readonly class FileAuthorizationService
{
    private const PREVIEW_PERMISSION = 'peanut.file.list';
    private const DOWNLOAD_ORIGINAL_PERMISSION = 'peanut.file.download-original';

    public function __construct(
        private AdminAuthorizationQuery $authorization,
        private TenantSessionAccess $sessions,
        private ModuleRuntimeRepository $modules,
        private ExecutionContextStore $execution,
        private FileDocumentReferenceRegistry $documents,
    ) {}

    /** Only current, installed and Tenant-enabled providers reach the content selector. */
    public function contentProviderModules(TenantContext $context): array
    {
        try {
            $guard = new ModuleGuard($this->modules);
            $guard->assertDeployment('peanut.content-tools');
            $guard->assertTenant($context->tenantId, 'peanut.content-tools', new DateTimeImmutable('now', new DateTimeZone('UTC')));
            return ['peanut.content-tools'];
        } catch (\PeanutAdmin\Kernel\Module\ModuleException) {
            return [];
        }
    }

    public function authorizePublic(array $object): string
    {
        $this->assertPublicObject($object);
        $guard = new ModuleGuard($this->modules);
        $installation = $guard->assertDeployment('peanut.file');
        $tenantModule = $guard->assertTenant((int) $object['tenant_id'], 'peanut.file', new DateTimeImmutable('now', new DateTimeZone('UTC')));
        return hash('sha256', json_encode([$installation, $tenantModule], JSON_THROW_ON_ERROR));
    }

    /** Object policy for callers that already validated this tenant's public authorization. */
    public function assertPublicObject(array $object): void
    {
        if ($object['status'] !== 'ready' || $object['access_type'] !== 'public' || !\PeanutAdmin\Modules\File\Value\Storage\StoragePurpose::allowsDocumentPreviewInheritance($object['purpose'])) {
            throw BusinessException::forbidden('FILE_PUBLIC_ACCESS_DENIED', '资源不能公开交付');
        }
    }

    public function requireManagementPermission(TenantContext $context, string $permission): void
    {
        $current = $this->grantContext(['tenant_id' => $context->tenantId, 'account_id' => $context->accountId,
            'member_id' => $context->memberId, 'session_key' => $context->sessionKey, 'client_key' => $context->clientKey]);
        if ($current->authorizationRevision !== $context->authorizationRevision) {
            throw BusinessException::forbidden('FILE_AUTHORIZATION_STALE', '资源授权已改变');
        }
        $principal = $this->authorization->principal($current);
        foreach (['peanut.file.manage', $permission] as $required) {
            if (!$this->authorization->decide($current, $principal, $required)->allowed) {
                throw BusinessException::forbidden('FILE_MANAGEMENT_DENIED', '无权管理资源');
            }
        }
        $guard = new ModuleGuard($this->modules);
        $guard->assertDeployment('peanut.file');
        $guard->assertTenant($current->tenantId, 'peanut.file', new DateTimeImmutable('now', new DateTimeZone('UTC')));
    }

    /** Business upload authority does not replace File's independent live upload permission. */
    public function requireEvidenceUploadPermission(TenantContext $context): void
    {
        $current = $this->sessions->revalidateSession($context->tenantId, $context->accountId, $context->memberId,
            $context->sessionKey, $context->clientKey, $context->requestId);
        if ($current->authorizationRevision !== $context->authorizationRevision
            || $current->tenantAuthorizationRevision !== $context->tenantAuthorizationRevision) {
            throw BusinessException::forbidden('FILE_AUTHORIZATION_STALE', '资源授权已改变');
        }
        $guard = new ModuleGuard($this->modules);
        $guard->assertDeployment('peanut.file');
        $guard->assertTenant($current->tenantId, 'peanut.file', new DateTimeImmutable('now', new DateTimeZone('UTC')));
        if (!$this->authorization->decide($current, $this->authorization->principal($current), 'peanut.file.upload.file')->allowed) {
            throw BusinessException::forbidden('FILE_ACCESS_DENIED', '无权上传私有凭证');
        }
    }

    /** Same current identity, Module and independent right used by final object authorization. */
    public function requireOriginalDownloadPermission(TenantContext $context): void
    {
        $current = $this->sessions->revalidateSession(
            $context->tenantId,
            $context->accountId,
            $context->memberId,
            $context->sessionKey,
            $context->clientKey,
            $context->requestId,
        );
        if ($current->authorizationRevision !== $context->authorizationRevision) {
            throw BusinessException::forbidden('FILE_AUTHORIZATION_STALE', '资源授权已改变');
        }
        $principal = $this->authorization->principal($current);
        $this->execution->run(new AdminExecutionContext($current, 'file.download_original', $principal->toArray()), function () use ($current, $principal): void {
            $guard = new ModuleGuard($this->modules);
            $guard->assertDeployment('peanut.file');
            $guard->assertTenant($current->tenantId, 'peanut.file', new DateTimeImmutable('now', new DateTimeZone('UTC')));
            $this->requireOperationPermission($current, $principal, 'download_original');
        });
    }

    /** File-internal check after live authorization of the delivery grant's preview. */
    public function requireOriginalPreviewPermission(TenantContext $current): void
    {
        $principal = $this->authorization->principal($current);
        $this->execution->run(new AdminExecutionContext($current, 'file.download_original', $principal->toArray()), function () use ($current, $principal): void {
            $this->requireOperationPermission($current, $principal, 'download_original');
        });
    }

    private function requireOperationPermission(TenantContext $context, AdminPrincipal $principal, string $operation): void
    {
        $permission = match ($operation) {
            'preview' => self::PREVIEW_PERMISSION,
            'download_original' => self::DOWNLOAD_ORIGINAL_PERMISSION,
            'manage' => 'peanut.file.manage',
        };
        if (!$this->authorization->decide($context, $principal, $permission)->allowed) {
            throw BusinessException::forbidden('FILE_ACCESS_DENIED', '无权执行资源操作');
        }
    }

    /** File-internal use after the owner has authorized its persisted reference set. */
    public function authorizeDocumentReference(TenantContext $context, array $object, int $documentId): array
    {
        try {
            return $this->authorize($context, $object, 'preview');
        } catch (BusinessException $error) {
            if ($error->errorCode !== 'FILE_ACCESS_DENIED' || !StoragePurpose::allowsDocumentPreviewInheritance($object['purpose'])) {
                throw $error;
            }
            return $this->authorize($context, $object, 'preview', $documentId);
        }
    }

    /** @return array{authorization_revision:string,reference_digest:?string,context:TenantContext} */
    public function authorize(TenantContext $context, array $object, string $operation, ?int $documentId = null): array
    {
        if (!in_array($operation, ['preview', 'download_original', 'manage'], true)) {
            throw BusinessException::forbidden('FILE_ACCESS_DENIED', '无权访问资源');
        }
        $this->assertReadableObject($context, $object);
        $current = $this->currentContext($context);
        $principal = $this->authorization->principal($current);
        return $this->authorizeCurrentOperation($current, $principal, $object, $operation, $documentId);
    }

    /** Contextual preview and independent original permission for one locked snapshot. */
    public function authorizeDocumentSnapshotPreview(TenantContext $context, array $object, int $documentId): array
    {
        $this->assertReadableObject($context, $object);
        $current = $this->currentContext($context);
        $principal = $this->authorization->principal($current);
        // For sensitive snapshots, the metadata check still denies missing direct File access first.
        if (!StoragePurpose::allowsDocumentPreviewInheritance($object['purpose'])) {
            $this->authorizeCurrentOperation($current, $principal, $object, 'preview', null);
        }
        $grant = $this->authorizeCurrentOperation($current, $principal, $object, 'preview', $documentId);
        $canDownload = false;
        try {
            $this->authorizeCurrentOperation($current, $principal, $object, 'download_original', null);
            $canDownload = true;
        } catch (BusinessException) {
            // Document preview authority never confers the independent original-download right.
        }
        return ['grant' => $grant, 'can_download_original' => $canDownload];
    }

    /** @return array{authorization_revision:string,reference_digest:?string,context:TenantContext} */
    private function authorizeCurrentOperation(TenantContext $current, AdminPrincipal $principal, array $object, string $operation, ?int $documentId): array
    {
        return $this->execution->run(new AdminExecutionContext($current, 'file.' . $operation, $principal->toArray()), function () use ($current, $principal, $object, $operation, $documentId): array {
            $guard = new ModuleGuard($this->modules);
            $installation = $guard->assertDeployment('peanut.file');
            $tenantModule = $guard->assertTenant($current->tenantId, 'peanut.file', new DateTimeImmutable('now', new DateTimeZone('UTC')));
            $referenceDigest = null;
            $documentRevision = '';
            if ($documentId !== null) {
                if ($operation !== 'preview' || $documentId < 1 || !StoragePurpose::allowsDocumentPreviewInheritance($object['purpose'])) {
                    throw BusinessException::forbidden('FILE_CONTEXTUAL_ACCESS_DENIED', '此资源需要独立授权');
                }
                $documentInstallation = $guard->assertDeployment('peanut.document');
                $documentModule = $guard->assertTenant($current->tenantId, 'peanut.document', new DateTimeImmutable('now', new DateTimeZone('UTC')));
                $grant = $this->documents->requirePolicy()->authorizePreview($current, $documentId, (string) $object['file_key']);
                if ($grant['inherit_preview'] !== true || $grant['authorization_revision'] === '' || $grant['reference_digest'] === '') {
                    throw BusinessException::forbidden('FILE_CONTEXTUAL_ACCESS_DENIED', '文档资源不允许继承预览权限');
                }
                $referenceDigest = $grant['reference_digest'];
                $documentRevision = $grant['authorization_revision'] . json_encode([$documentInstallation, $documentModule], JSON_THROW_ON_ERROR);
            } else {
                $this->requireOperationPermission($current, $principal, $operation);
            }
            return [
                'context' => $current,
                'authorization_revision' => $this->authorizationRevision($current, $operation, $documentRevision, $installation, $tenantModule),
                'reference_digest' => $referenceDigest,
            ];
        });
    }

    /**
     * File-internal authorization for direct previews after checking the first page object.
     * @return array{grant:array{context:TenantContext,authorization_revision:string,tenant_authorization_revision:int,reference_digest:null},can_download_original:bool}
     */
    public function authorizePreviewPage(TenantContext $context): array
    {
        $current = $this->currentContext($context);
        $principal = $this->authorization->principal($current);
        return $this->execution->run(new AdminExecutionContext($current, 'file.preview', $principal->toArray()), function () use ($current): array {
            $guard = new ModuleGuard($this->modules);
            $installation = $guard->assertDeployment('peanut.file');
            $tenantModule = $guard->assertTenant($current->tenantId, 'peanut.file', new DateTimeImmutable('now', new DateTimeZone('UTC')));
            try {
                $decisions = $this->authorization->decisions($current, [self::PREVIEW_PERMISSION, self::DOWNLOAD_ORIGINAL_PERMISSION]);
            } catch (\DomainException $error) {
                if ($error->getMessage() !== 'TENANT_ADMIN_PRINCIPAL_UNAVAILABLE') {
                    throw $error;
                }
                throw BusinessException::forbidden('FILE_ACCESS_DENIED', '无权执行资源操作');
            }
            if (!$decisions[self::PREVIEW_PERMISSION]->allowed) {
                throw BusinessException::forbidden('FILE_ACCESS_DENIED', '无权执行资源操作');
            }
            return [
                'grant' => [
                    'context' => $current,
                    'authorization_revision' => $this->authorizationRevision($current, 'preview', '', $installation, $tenantModule),
                    // Role permissions can change without changing the member revision.
                    'tenant_authorization_revision' => $current->tenantAuthorizationRevision,
                    'reference_digest' => null,
                ],
                'can_download_original' => $decisions[self::DOWNLOAD_ORIGINAL_PERMISSION]->allowed,
            ];
        });
    }

    /** File-internal object check shared by page and single-object authorization. */
    public function assertReadableObject(TenantContext $context, array $object): void
    {
        if ($context->tenantId !== (int) $object['tenant_id'] || $object['status'] !== 'ready') {
            throw BusinessException::forbidden('FILE_ACCESS_DENIED', '无权访问资源');
        }
    }

    private function currentContext(TenantContext $context): TenantContext
    {
        $current = $this->sessions->revalidateSession(
            $context->tenantId,
            $context->accountId,
            $context->memberId,
            $context->sessionKey,
            $context->clientKey,
            $context->requestId,
        );
        if ($current->authorizationRevision !== $context->authorizationRevision) {
            throw BusinessException::forbidden('FILE_AUTHORIZATION_STALE', '资源授权已改变');
        }
        return $current;
    }

    private function authorizationRevision(
        TenantContext $context,
        string $operation,
        string $documentRevision,
        ModuleInstallationRecord $installation,
        TenantModuleRecord $tenantModule,
    ): string {
        return hash('sha256', json_encode([$context->authorizationRevision, $operation, $documentRevision, $installation, $tenantModule], JSON_THROW_ON_ERROR));
    }

    /** Current independent original-download right under the existing System root; never creates an Admin root. */
    public function authorizeBackgroundDocumentSource(TenantContext $context, array $object): void
    {
        $root = $this->execution->current();
        if (!$root instanceof \app\common\execution\SystemExecutionContext || $root->tenantId() !== $context->tenantId
            || $root->system->actorKey !== 'task-worker' || $root->operation() !== 'async.worker'
            || $root->metadata->jobKey !== $root->system->operationId || $root->metadata->handlerKey === null
            || $root->metadata->attemptNumber === null || $root->metadata->attemptNumber < 1
            || $context->tenantId !== (int) $object['tenant_id'] || $object['status'] !== 'ready') {
            throw BusinessException::forbidden('FILE_SOURCE_CONTEXT_DENIED', '固定来源校验需要当前后台任务上下文');
        }
        $current = $this->sessions->revalidateSession(
            $context->tenantId,
            $context->accountId,
            $context->memberId,
            $context->sessionKey,
            $context->clientKey,
            $context->requestId,
        );
        if ($current->authorizationRevision !== $context->authorizationRevision
            || $current->tenantAuthorizationRevision !== $context->tenantAuthorizationRevision) {
            throw BusinessException::forbidden('FILE_AUTHORIZATION_STALE', '资源授权已改变');
        }
        $this->requireBackgroundOriginalDownload($current);
    }

    public function authorizeBackgroundImportSource(AuthorizedOperationContext $context, array $object): void
    {
        $actor = $context->tenantContext;
        $root = $this->execution->current();
        if (!$root instanceof \app\common\execution\SystemExecutionContext
            || $root->tenantId() !== $actor->tenantId
            || $root->system->actorKey !== 'task-worker'
            || $root->operation() !== 'async.worker'
            || $root->metadata->handlerKey !== 'peanut.import-export.execute'
            || $context->resourceKey !== 'peanut.import-export' || $context->operation !== 'create'
            || $actor->tenantId !== (int) $object['tenant_id'] || $object['status'] !== 'ready') {
            throw BusinessException::forbidden('FILE_SOURCE_CONTEXT_DENIED', '导入来源需要当前后台任务上下文');
        }
        $this->requireBackgroundOriginalDownload($actor);
    }

    private function requireBackgroundOriginalDownload(TenantContext $actor): void
    {
        $guard = new ModuleGuard($this->modules);
        $guard->assertDeployment('peanut.file');
        $guard->assertTenant($actor->tenantId, 'peanut.file', new DateTimeImmutable('now', new DateTimeZone('UTC')));
        $this->requireOperationPermission($actor, $this->authorization->principal($actor), 'download_original');
    }

    /** Restores only a verified durable grant's identity through Identity's live validation. */
    public function grantContext(array $subject): TenantContext
    {
        return $this->sessions->revalidateSession(
            (int) $subject['tenant_id'],
            (int) $subject['account_id'],
            (int) $subject['member_id'],
            (string) $subject['session_key'],
            (string) $subject['client_key'],
            'file-delivery-' . bin2hex(random_bytes(8)),
        );
    }
}
