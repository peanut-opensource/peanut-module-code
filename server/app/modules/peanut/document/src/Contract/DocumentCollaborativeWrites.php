<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Document\Contract;

use PeanutAdmin\Kernel\Auth\TenantContext;

interface DocumentCollaborativeWrites
{
    /** @return array{status: 'OLD_STALE'|'CURRENT_FRESH'|'UNKNOWN'} Only the claimed negative fact, no Admin/grant/content result. */
    public function compareRevocationGrant(TenantContext $identity, int $documentId, string $mode, string $issuedRevision, string $factId, string $factHash): array;
    /** Current human authorization; caller holds an outer Admin root and native transaction. */
    public function authorizeCollaboration(TenantContext $context, int $documentId, string $mode, bool $enable = false): array;
    /** No current-human shortcut: compiled provider must prove the accepted exact-byte capture. */
    public function commitAccepted(AcceptedStoreInput $input): DurableSaveReceipt;
    /** Server-held read/write mode selects current view/edit checks; service HMAC does not grant human access. */
    public function loadAuthorizedCollaboration(TenantContext $context, int $documentId, DocumentOwnerReference $owner, string $mode): array;
    public function collaborationReceipt(int $documentId, DocumentOwnerReference $owner, string $storeId, string $captureHash): ?DurableSaveReceipt;
    public function recoverCollaborationEpoch(int $documentId, DocumentOwnerReference $owner, int $expectedEpoch, string $requestId): array;
    /** Explicit native owner cutover, preserving archived status; never a human permission shortcut. */
    public function migrateCollaborationEpoch(int $documentId, DocumentOwnerReference $owner, int $expectedEpoch, string $requestId): array;
    public function releaseFencedCollaborationReferences(int $documentId, DocumentOwnerReference $owner, int $oldEpoch): void;
    /** Registers the signed service's frozen click target under this live actor, after actual receipt verification. */
    public function registerExplicitTarget(TenantContext $context, int $documentId, DocumentOwnerReference $owner, ExplicitTargetInput $input): array;
    public function prepareExplicitTarget(TenantContext $context, int $documentId, DocumentOwnerReference $owner, ExplicitTargetPreparationInput $input): array;
}
