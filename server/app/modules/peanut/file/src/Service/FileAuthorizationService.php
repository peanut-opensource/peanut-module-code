<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\File\Service;

use app\common\contract\authorization\AdminAuthorizationQuery;
use app\common\exception\BusinessException;
use app\common\execution\AdminExecutionContext;
use app\common\execution\ExecutionContextStore;
use DateTimeImmutable;
use DateTimeZone;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Module\ModuleGuard;
use PeanutAdmin\Kernel\Module\ModuleRuntimeRepository;
use PeanutAdmin\Modules\Identity\Contract\TenantSessionAccess;

/** Live identity/RBAC/Module and contextual policy, never a parallel resource ACL. */
final readonly class FileAuthorizationService
{
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
        if ($object['status'] !== 'ready' || $object['access_type'] !== 'public' || !\PeanutAdmin\Modules\File\Value\Storage\StoragePurpose::allowsDocumentPreviewInheritance($object['purpose'])) {
            throw BusinessException::forbidden('FILE_PUBLIC_ACCESS_DENIED', '资源不能公开交付');
        }
        $guard = new ModuleGuard($this->modules);
        $installation = $guard->assertDeployment('peanut.file');
        $tenantModule = $guard->assertTenant((int) $object['tenant_id'], 'peanut.file', new DateTimeImmutable('now', new DateTimeZone('UTC')));
        return hash('sha256', json_encode([$installation, $tenantModule], JSON_THROW_ON_ERROR));
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

    private function requireOperationPermission(TenantContext $context, \app\common\dto\authorization\AdminPrincipal $principal, string $operation): void
    {
        $permission = match ($operation) {
            'preview' => 'peanut.file.list',
            'download_original' => 'peanut.file.download-original',
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
            if ($error->errorCode !== 'FILE_ACCESS_DENIED' || !\PeanutAdmin\Modules\File\Value\Storage\StoragePurpose::allowsDocumentPreviewInheritance($object['purpose'])) {
                throw $error;
            }
            return $this->authorize($context, $object, 'preview', $documentId);
        }
    }

    /** @return array{authorization_revision:string,reference_digest:?string,context:TenantContext} */
    public function authorize(TenantContext $context, array $object, string $operation, ?int $documentId = null): array
    {
        if (!in_array($operation, ['preview', 'download_original', 'manage'], true)
            || $context->tenantId !== (int) $object['tenant_id'] || $object['status'] !== 'ready') {
            throw BusinessException::forbidden('FILE_ACCESS_DENIED', '无权访问资源');
        }
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
        return $this->execution->run(new AdminExecutionContext($current, 'file.' . $operation, $principal->toArray()), function () use ($current, $principal, $object, $operation, $documentId): array {
            $guard = new ModuleGuard($this->modules);
            $installation = $guard->assertDeployment('peanut.file');
            $tenantModule = $guard->assertTenant($current->tenantId, 'peanut.file', new DateTimeImmutable('now', new DateTimeZone('UTC')));
            $referenceDigest = null;
            $documentRevision = '';
            if ($documentId !== null) {
                if ($operation !== 'preview' || $documentId < 1 || !\PeanutAdmin\Modules\File\Value\Storage\StoragePurpose::allowsDocumentPreviewInheritance($object['purpose'])) {
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
                'authorization_revision' => hash('sha256', json_encode([$current->authorizationRevision, $operation, $documentRevision, $installation, $tenantModule], JSON_THROW_ON_ERROR)),
                'reference_digest' => $referenceDigest,
            ];
        });
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
