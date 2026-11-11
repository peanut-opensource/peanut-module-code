<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\File\Contract;

use PeanutAdmin\Kernel\Auth\TenantContext;

/** Document binding metadata and actual immutable private bytes, independently authorized by File. */
interface FileDocumentSnapshots
{
    /** @return array{file_key:string,verified_mime_type:string,sha256:string,revision:int} */
    public function describeForDocument(TenantContext $context, string $fileKey): array;
    /** Lock and authorize a claimed immutable version's File-owned metadata; no delivery or provider I/O. */
    public function assertDocumentSnapshotReadable(TenantContext $context, int $documentId, string $fileKey, string $sha256, string $verifiedMimeType): void;
    /** Durable capture before the owner's outer transaction; that transaction later claims the reference.
     * @return array{file_key:string,verified_mime_type:string,sha256:string,source_file_key:string,capture_id:string} */
    public function captureForDocument(TenantContext $context, string $fileKey, string $expectedSha256, int $expectedRevision, string $captureId): array;
    /** Requires the owner's existing native transaction and its already persisted, authorized reference. */
    public function claimDocumentSnapshot(TenantContext $context, int $documentId, array $snapshot): void;
    /** Only same-process new, unreferenced capture may be abandoned. Never a public HTTP action. */
    public function abandonDocumentSnapshot(TenantContext $context, array $snapshot): void;
    /** File management recovery of old unclaimed captures, including interrupted writes. No claimed version can be reclaimed. */
    public function pendingDocumentSnapshots(TenantContext $context): array;
    public function recoverDocumentSnapshot(TenantContext $context, string $fileKey): array;
}
