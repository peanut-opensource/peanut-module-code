<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Document\Contract;

use PeanutAdmin\Kernel\Auth\TenantContext;

interface DocumentCommands
{
    /** Current owner purge authorization and retention protection, inside the business transaction. */
    public function assertOwnerDeletionAllowed(TenantContext $context, DocumentOwnerReference $owner): void;
    public function create(TenantContext $context, DocumentOwnerReference $owner, string $contentKind, string $requestId, ?string $fileKey = null): array;
    public function saveSolo(TenantContext $context, int $documentId, int $expectedStateSeq, array $structuredContent, string $requestId): array;
    public function bindResource(TenantContext $context, int $documentId, string $fileKey, int $expectedStateSeq, string $requestId): array;
    public function freeze(TenantContext $context, int $documentId, int $expectedStateSeq, string $reason, string $requestId): array;
    /** Owner business preparation, in its existing transaction; an explicit target is not canceled after this claim. */
    public function beginExplicitTargetConsumption(TenantContext $context, int $documentId, int $expectedStateSeq, string $reason, string $requestId): void;
    public function cancelExplicitTarget(TenantContext $context, int $documentId, string $requestId): array;
    public function restoreRevision(TenantContext $context, int $documentId, int $revisionId, int $expectedStateSeq, string $requestId): array;
    public function archive(TenantContext $context, int $documentId, int $expectedAuthRevision, string $requestId): array;
    public function restore(TenantContext $context, int $documentId, int $expectedAuthRevision, string $requestId): array;
    /** Coordinates all owner slots: short prepare TX, remote drain, atomic owner callback + Documents, release after commit. */
    public function runOwnerLifecycle(TenantContext $context, DocumentOwnerReference $owner, string $action, callable $commitOwner): mixed;
    /** Short owner version-Save transaction with native current authorization locks. No RPC in callback. */
    public function runOwnerVersionSave(TenantContext $context, DocumentOwnerReference $owner, callable $commitOwner): mixed;
}
