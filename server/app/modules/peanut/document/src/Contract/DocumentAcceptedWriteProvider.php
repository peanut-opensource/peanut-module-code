<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Document\Contract;

use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Modules\File\Contract\CollaborationReferenceInput;

interface DocumentAcceptedWriteProvider
{
    /** Live human/owner edit locks precede provider Room/grant and File locks. DTO alone grants nothing. */
    public function assertReferenceReservation(
        TenantContext $current,
        CollaborationReferenceInput $input,
        DocumentOwnerReference $actualOwner,
        string $currentRevision,
    ): void;
    /** Current authenticated service/room binding; caller holds owner/Document locks. */
    public function assertServiceDocument(int $documentId, DocumentOwnerReference $owner): void;
    /** Verified same actor/connection reference reservations for an explicit click, never a file capability DTO. */
    public function targetReferenceReservations(TenantContext $current, int $documentId, DocumentOwnerReference $owner): array;
    public function fencedReferenceReservations(int $documentId, DocumentOwnerReference $owner, int $oldEpoch): array;
    public function retireFencedReferences(int $documentId, DocumentOwnerReference $owner, int $oldEpoch): void;
    /** Caller holds owner -> Document/state/operation locks in the same native transaction. */
    public function lockAcceptedCapture(AcceptedStoreInput $input): AcceptedWritePlan;
    public function markPersisted(AcceptedWritePlan $plan, DurableSaveReceipt $receipt): void;
    /** Called after owner/Doc/state/operation locks, in the caller's same PDO transaction. */
    public function prepareBusinessBarrier(TenantContext $current, int $documentId, DocumentOwnerReference $owner, string $action, string $operationId, string $inputSha256, int $stateSeq, int $authRevision, string $ownerRevision): BusinessBarrierPlan;
    /** Outside any SQL transaction: retry/query the exact original plan; uncertainty keeps admission closed. */
    public function drainBusinessBarrier(BusinessBarrierPlan $plan): void;
    public function lockDrainedBusinessBarrier(TenantContext $current, BusinessBarrierPlan $plan): DrainedBusinessBarrier;
    /** Verifies this connection's staged Document operation before consuming the original target. */
    public function consumeBusinessBarrier(DrainedBusinessBarrier $proof, DocumentBusinessReceipt $businessResult): void;
    /** After the outer commit, reload/close and confirm release of the same business result. */
    public function releaseBusinessBarrier(BusinessBarrierPlan $plan, DocumentBusinessReceipt $businessResult): void;
}
