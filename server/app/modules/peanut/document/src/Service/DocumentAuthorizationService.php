<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Document\Service;

use app\common\contract\authorization\AdminAuthorizationQuery;
use app\common\exception\BusinessException;
use app\common\execution\AdminExecutionContext;
use app\common\execution\ExecutionContextStore;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Module\ModuleGuard;
use PeanutAdmin\Kernel\Module\ModuleRuntimeRepository;
use PeanutAdmin\Modules\Identity\Contract\TenantSessionAccess;
use PeanutAdmin\Modules\Document\Contract\DocumentOwnerReference;
use PeanutAdmin\Modules\Document\Model\Document;

final readonly class DocumentAuthorizationService
{
    public function __construct(
        private TenantSessionAccess $sessions,
        private AdminAuthorizationQuery $authorization,
        private ExecutionContextStore $execution,
        private ModuleRuntimeRepository $modules,
        private DocumentOwnerRegistry $owners,
        private \PeanutAdmin\Modules\Identity\Contract\TenantAuthorizationReadFence $fence,
    ) {}

    /** Locate only server-owned metadata, then retain IAM locks before any Owner/Document lock. */
    public function prepareGrant(TenantContext $context, int $id, string $action): array
    {
        $document = $this->grantMetadata($id);
        [$permissions, $modules] = $this->authorityScope($document, $action);
        $pdo = \think\facade\Db::connect()->getPdo();
        $digest = $this->fence->captureGrant($context, $permissions, $modules);
        return ['reference' => self::reference($document), 'permissions' => $permissions, 'modules' => $modules,
            'digest' => $digest, 'pdo' => $pdo, 'action' => $action];
    }

    /** Closed three-state result. This never creates an Admin root, transaction, grant or content result. */
    public function compareRevocationGrant(TenantContext $identity, int $id, string $mode, string $issuedRevision, string $factId, string $factHash): array
    {
        $this->fence->assertRevocationScope($identity, $factId, $factHash);
        if (!in_array($mode, ['read', 'write'], true) || preg_match('/^[0-9a-f]{64}$/D', $issuedRevision) !== 1) {
            return ['status' => 'UNKNOWN'];
        }
        try {
            try {
                $current = $this->sessions->revalidateSession(
                    $identity->tenantId,
                    $identity->accountId,
                    $identity->memberId,
                    $identity->sessionKey,
                    $identity->clientKey,
                    $identity->requestId,
                );
            } catch (\PeanutAdmin\Modules\Identity\Contract\TenantSessionAccessException) {
                return ['status' => 'OLD_STALE'];
            }
            if ($current->authorizationRevision !== $identity->authorizationRevision) {
                return ['status' => 'UNKNOWN'];
            }
            $action = $mode === 'write' ? 'edit' : 'view';
            $document = $this->grantMetadata($id);
            [$permissions, $modules] = $this->authorityScope($document, $action);
            $before = $this->fence->revocationDigest($current, $permissions, $modules, $factId, $factHash);
            $policy = $this->owners->requirePolicy(self::reference($document));
            $owner = $policy->resolvePersistedOwner($current->tenantId, self::reference($document), false);
            $ownerVersion = $policy->stateRevision($owner);
            $stale = $document->status !== 'active' || $owner->archived || $document->content_kind !== 'rich_text' || !(bool) $document->collaboration_enabled;
            $permissionDenied = false;
            $revision = null;
            if (!$stale) {
                try {
                    foreach ($modules as $module) {
                        $this->requireModule($current, $module);
                    }
                    // The same real OwnerPolicy decision used by positive grants, under the original System root.
                    $policy->authorize($current, $action, $owner);
                    $revision = $this->composeRevision($current, $document, $action, $before, $owner);
                } catch (BusinessException $error) {
                    if ($error->errorCode !== 'ARTICLE_DOCUMENT_DENIED') {
                        return ['status' => 'UNKNOWN'];
                    }
                    $permissionDenied = true;
                } catch (\PeanutAdmin\Kernel\Module\ModuleException $error) {
                    if (!in_array($error->errorCode, ['MODULE_TENANT_DISABLED', 'MODULE_TENANT_NOT_EFFECTIVE'], true)) {
                        return ['status' => 'UNKNOWN'];
                    }
                    foreach ($modules as $module) {
                        $record = $this->modules->tenantModule($current->tenantId, $module);
                        if ($record === null) {
                            return ['status' => 'UNKNOWN'];
                        }
                        $stale = $stale || $record->status === 'disabled' || !$record->isEffective(new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
                    }
                    if (!$stale) {
                        return ['status' => 'UNKNOWN'];
                    }
                }
            }
            if ($permissionDenied) {
                $principal = $this->authorization->principal($current);
                $denied = false;
                foreach ($permissions as $permission) {
                    $decision = $this->authorization->decide($current, $principal, $permission);
                    if (!$decision->allowed && $decision->reason !== 'PERMISSION_NOT_GRANTED') {
                        return ['status' => 'UNKNOWN'];
                    }
                    $denied = $denied || !$decision->allowed;
                }
                if (!$denied) {
                    return ['status' => 'UNKNOWN'];
                }
                $stale = true;
            }
            $after = $this->fence->revocationDigest($current, $permissions, $modules, $factId, $factHash);
            $verified = $this->grantMetadata($id);
            $verifiedOwner = $policy->resolvePersistedOwner($current->tenantId, self::reference($document), false);
            if (!hash_equals($before, $after) || $document->toArray() !== $verified->toArray()
                || $ownerVersion !== $policy->stateRevision($verifiedOwner)) {
                return ['status' => 'UNKNOWN'];
            }
            if ($stale) {
                return ['status' => 'OLD_STALE'];
            }
            return ['status' => $revision === null ? 'UNKNOWN'
                : (hash_equals($issuedRevision, $revision) ? 'CURRENT_FRESH' : 'OLD_STALE')];
        } catch (\Throwable) {
            // Source/fact/identity gates above are not hidden by this classification boundary.
            return ['status' => 'UNKNOWN'];
        }
    }

    private function grantMetadata(int $id): Document
    {
        $document = Document::where('id', $id)->field('id,tenant_id,owner_module_key,owner_object_type,owner_object_id,slot,status,auth_revision,content_kind,collaboration_enabled')->find();
        if ($document === null) {
            throw BusinessException::notFound('DOCUMENT_NOT_FOUND', '文档不存在');
        }
        return $document;
    }

    private function authorityScope(Document $document, string $action): array
    {
        $reference = self::reference($document);
        $permissions = $this->owners->requirePolicy($reference)->authorizationPermissionKeys($document->status === 'archived' ? 'archive.read' : $action);
        $modules = array_values(array_unique(['peanut.document', 'peanut.collaboration', $reference->moduleKey]));
        sort($permissions, SORT_STRING);
        sort($modules, SORT_STRING);
        return [$permissions, $modules];
    }

    public function run(TenantContext $context, callable $operation): mixed
    {
        $ambient = $this->execution->current();
        if ($ambient !== null && (!$ambient instanceof AdminExecutionContext || $ambient->tenant->tenantId !== $context->tenantId
            || $ambient->tenant->memberId !== $context->memberId || $ambient->tenant->accountId !== $context->accountId
            || $ambient->tenant->clientKey !== $context->clientKey || !hash_equals($ambient->tenant->sessionKey, $context->sessionKey))) {
            throw BusinessException::forbidden('DOCUMENT_CONTEXT_MISMATCH', '文档与当前可信会话不一致');
        }
        try {
            $current = $this->sessions->revalidateSession(
                $context->tenantId,
                $context->accountId,
                $context->memberId,
                $context->sessionKey,
                $context->clientKey,
                $context->requestId,
            );
        } catch (\PeanutAdmin\Modules\Identity\Contract\TenantSessionAccessException) {
            throw BusinessException::forbidden('DOCUMENT_SESSION_DENIED', '原文档会话已失效');
        }
        if ($current->authorizationRevision !== $context->authorizationRevision) {
            throw BusinessException::forbidden('DOCUMENT_AUTHORIZATION_STALE', '文档授权已改变');
        }
        $principal = $this->authorization->principal($current);
        return $this->execution->run(new AdminExecutionContext($current, 'document.operation', $principal->toArray()), function () use ($current, $operation) {
            $this->requireModule($current, 'peanut.document');
            return $operation($current);
        });
    }

    /** @return array{\PeanutAdmin\Kernel\Module\ModuleInstallationRecord, \PeanutAdmin\Kernel\Module\TenantModuleRecord} */
    public function requireModule(TenantContext $context, string $moduleKey): array
    {
        $guard = new ModuleGuard($this->modules);
        return [$guard->assertDeployment($moduleKey),
            $guard->assertTenant($context->tenantId, $moduleKey, new \DateTimeImmutable('now', new \DateTimeZone('UTC')))];
    }

    /** Deployment availability does not confer human or accepted-write authority. */
    public function requireDeploymentModule(string $moduleKey): void
    {
        (new ModuleGuard($this->modules))->assertDeployment($moduleKey);
    }

    /** @param-out array{mixed,mixed} $moduleState */
    public function owner(TenantContext $context, DocumentOwnerReference $reference, string $action, bool $lock, ?array &$moduleState = null): \PeanutAdmin\Modules\Document\Contract\DocumentOwnerState
    {
        $moduleState = $this->requireModule($context, $reference->moduleKey);
        $policy = $this->owners->requirePolicy($reference);
        $owner = $policy->resolveOwner($context, $reference, $lock);
        if ($owner->tenantId !== $context->tenantId || $owner->reference != $reference) {
            throw BusinessException::forbidden('DOCUMENT_OWNER_MISMATCH', '文档业务归属不一致');
        }
        $policy->authorize($context, $action, $owner);
        return $owner;
    }

    /** Authorize a read once, after capturing the current revision inputs. */
    public function readDocument(TenantContext $context, int $id): array
    {
        if ($id < 1 || ($document = Document::where('id', $id)->find()) === null) {
            throw BusinessException::notFound('DOCUMENT_NOT_FOUND', '文档不存在');
        }
        [$permissions, $modules] = $this->authorityScope($document, 'view');
        $digest = $this->fence->currentDigest($context, $permissions, $modules);
        $action = $document->status === 'archived' ? 'archive.read' : 'view';
        $owner = $this->owner($context, self::reference($document), $action, false, $moduleState);
        if ($action === 'view' ? $document->status !== 'active' || $owner->archived : !$owner->archived) {
            throw BusinessException::forbidden('DOCUMENT_STATE_DENIED', '当前业务或文档状态不允许此操作');
        }
        return [$document, $this->composeRevision($context, $document, 'view', $digest, $owner, $moduleState)];
    }

    /** Owner row is locked before the Document row on all mutation paths. */
    public function document(TenantContext $context, int $id, string $action, bool $lock = false): Document
    {
        if ($id < 1) {
            throw BusinessException::notFound('DOCUMENT_NOT_FOUND', '文档不存在');
        }
        $document = Document::where('id', $id)->find();
        if ($document === null) {
            throw BusinessException::notFound('DOCUMENT_NOT_FOUND', '文档不存在');
        }
        $reference = self::reference($document);
        if ($action === 'view' && $document->status === 'archived') {
            $action = 'archive.read';
        }
        $owner = $this->owner($context, $reference, $action, $lock);
        if ($lock) {
            $document = Document::where('id', $id)->lock(true)->find();
            if ($document === null || self::reference($document) != $reference) {
                throw BusinessException::conflict('DOCUMENT_OWNER_CHANGED', '文档业务归属已改变');
            }
        }
        $valid = match ($action) {
            'view','edit','version.create','create' => $document->status === 'active' && !$owner->archived,
            'archive.read' => $document->status === 'archived' && $owner->archived,
            'archive' => in_array($document->status, ['active','archiving','archived'], true) && $owner->archived,
            'restore' => in_array($document->status, ['active','archived'], true) && !$owner->archived,
            default => false,
        };
        if (!$valid) {
            throw BusinessException::forbidden('DOCUMENT_STATE_DENIED', '当前业务或文档状态不允许此操作');
        }
        return $document;
    }

    public function revision(TenantContext $context, Document $document, string $action = 'view', ?array $grant = null): string
    {
        [$permissions, $modules] = $this->authorityScope($document, $action);
        if ($grant !== null) {
            if ($grant['pdo'] !== \think\facade\Db::connect()->getPdo() || !$grant['pdo']->inTransaction()
                || $grant['reference'] != self::reference($document) || $grant['action'] !== $action
                || $grant['permissions'] !== $permissions || $grant['modules'] !== $modules) {
                throw new \DomainException('DOCUMENT_GRANT_SCOPE_CHANGED');
            }
            $digest = $grant['digest'];
        } else {
            $digest = $this->fence->currentDigest($context, $permissions, $modules);
        }
        $owner = $this->owner($context, self::reference($document), $document->status === 'archived' ? 'archive.read' : $action, false);
        return $this->composeRevision($context, $document, $action, $digest, $owner);
    }

    /** The caller has just authorized edit on this exact, locked business owner. */
    public function lockedEditorRevision(TenantContext $context, Document $document, \PeanutAdmin\Modules\Document\Contract\DocumentOwnerState $owner, string $digest): string
    {
        return $this->composeRevision($context, $document, 'edit', $digest, $owner);
    }

    private function composeRevision(TenantContext $context, Document $document, string $action, string $digest, \PeanutAdmin\Modules\Document\Contract\DocumentOwnerState $owner, ?array $moduleState = null): string
    {
        [$permissions, $modules] = $this->authorityScope($document, $action);
        $guard = new ModuleGuard($this->modules);
        $moduleState ??= [$guard->assertDeployment($owner->reference->moduleKey),$guard->assertTenant($context->tenantId, $owner->reference->moduleKey, new \DateTimeImmutable('now', new \DateTimeZone('UTC')))];
        // Editing progress belongs to state_seq/CAS receipts, never to a connection's authorization grant.
        return hash('sha256', json_encode(['peanut.document.grant-authorization.v1', $context->tenantId, $context->memberId,
            (int) $document->id, $action, [$owner->reference->moduleKey, $owner->reference->objectType, $owner->reference->objectId, $owner->reference->slot],
            $permissions, $modules, $digest, $context->authorizationRevision, $document->auth_revision, $document->status,
            $this->owners->requirePolicy($owner->reference)->stateRevision($owner), $moduleState], JSON_THROW_ON_ERROR));
    }

    public static function reference(Document $document): DocumentOwnerReference
    {
        return new DocumentOwnerReference(
            (string) $document->owner_module_key,
            (string) $document->owner_object_type,
            (string) $document->owner_object_id,
            (string) $document->slot,
        );
    }
}
