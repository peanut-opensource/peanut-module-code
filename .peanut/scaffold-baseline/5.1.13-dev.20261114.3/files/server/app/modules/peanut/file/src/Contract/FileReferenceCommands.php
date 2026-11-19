<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\File\Contract;

use PeanutAdmin\Kernel\Auth\TenantContext;

interface FileReferenceCommands
{
    /** Rebuild only a currently authorized Document's references, in the caller's transaction. @param list<string> $fileKeys */
    public function replaceDocumentReferences(TenantContext $context, int $documentId, array $fileKeys): void;
    /** Authorize historical references without changing the index or issuing delivery. Requires caller transaction. @param list<string> $fileKeys */
    public function assertDocumentReferencesReadable(TenantContext $context, int $documentId, array $fileKeys): void;
    public function reserveCollaborationReferences(TenantContext $actor, CollaborationReferenceInput $input): void;
    public function reserveExplicitTargetReferences(TenantContext $actor, CollaborationReferenceInput $input): void;
    public function consumeExplicitTargetReferences(TenantContext $actor, CollaborationReferenceInput $input): void;
    public function cancelExplicitTargetReferences(TenantContext $actor, CollaborationReferenceInput $input): void;
    public function commitAcceptedDocumentReferences(AcceptedReferenceTransfer $input): void;
    public function releaseSettledCollaborationReferences(ReservationReleaseInput $input): void;
}
