<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\File\Contract;

use PeanutAdmin\Kernel\Auth\TenantContext;

/** Document owns current business authorization and its durable reference, File owns byte access. */
interface FileDocumentReferencePolicy
{
    /**
     * Throws when the document/current reference is not authorized.
     * @return array{authorization_revision:string,reference_digest:string,inherit_preview:bool}
     */
    public function authorizePreview(TenantContext $context, int $documentId, string $fileKey): array;

    /** Authorize editing and lock the persisted owner on the caller's ThinkPHP connection/transaction,
     * then return its current persisted references (including an empty set).
     * @return array{authorization_revision:string,file_keys:list<string>,reference_digest:string}
     */
    public function referencesForUpdate(TenantContext $context, int $documentId): array;
    /** Live edit policy before any File lock; only usage=current may inherit non-sensitive preview. */
    public function collaborationReferencesForUpdate(TenantContext $context, CollaborationReferenceInput $input): array;
    /** Registered immutable explicit target under the caller's same owner/Document transaction. */
    public function explicitTargetReferencesForUpdate(TenantContext $context, CollaborationReferenceInput $input, bool $consume): array;
    /** Original registered transfer, held owner/Doc/provider locks and exact durable current/history union. */
    public function acceptedReferencesForUpdate(AcceptedReferenceTransfer $input): array;
    /** Exact original registered settlement proof; unknown/pending/TTL are not cleanup authority. */
    public function settledReservationsForUpdate(ReservationReleaseInput $input): array;
}
