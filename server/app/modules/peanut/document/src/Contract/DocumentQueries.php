<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Document\Contract;

use PeanutAdmin\Kernel\Auth\TenantContext;

interface DocumentQueries
{
    /** Current published owner's fixed rich-text projection only. No caller-selected tenant/version or File grant. */
    public function publicationBody(DocumentOwnerReference $owner): array;
    /** Metadata/projection only: no Yjs binary, storage address or collaboration ticket. */
    public function detail(TenantContext $context, int $documentId, ?int $revisionId = null): array;
    /** Committed summaries only; fresh view/archive.read authorization on every bounded page. Rejects outer transactions. */
    public function versions(TenantContext $context, int $documentId, int $limit = 20, ?int $beforeRevisionNo = null): array;
    public function capabilities(TenantContext $context, int $documentId, string $intent, ?int $revisionId = null): array;
    public function businessOperation(TenantContext $context, int $documentId, string $operationId): array;
    public function explicitTarget(TenantContext $context, int $documentId, string $requestId): array;
    /** Only committed versions; rejects callers holding an outer transaction. */
    public function versionSnapshot(TenantContext $context, int $documentId, int $revisionId): VersionSnapshot;
    /** Owner edit authorization, never implicit ordinary view/detail authority. */
    public function ownerEditor(TenantContext $context, int $documentId, DocumentOwnerReference $owner): array;
    /** A committed version for this owner's Save only; requires version.create, not view. */
    public function ownerSaveSnapshot(TenantContext $context, int $documentId, int $revisionId, DocumentOwnerReference $owner): VersionSnapshot;
    /** Same original object, live outer Admin root and caller transaction; never a serializable credential. */
    public function verifyPreparedVersion(TenantContext $context, VersionSnapshot $snapshot, DocumentOwnerReference $expectedOwner): void;
}
