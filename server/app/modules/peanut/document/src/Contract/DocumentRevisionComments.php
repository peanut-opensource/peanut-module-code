<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Document\Contract;

use PeanutAdmin\Kernel\Auth\TenantContext;

/**
 * Comments on one committed immutable revision; never on the mutable draft.
 * Offsets count Unicode code points in the returned text profile, in [start, end).
 * Every read requires current owner view; every command also requires current owner edit.
 */
interface DocumentRevisionComments
{
    public function threads(TenantContext $context, int $documentId, int $revisionId, int $limit = 20, ?int $beforeThreadId = null): array;
    public function thread(TenantContext $context, int $documentId, int $revisionId, int $threadId, int $limit = 50, ?int $afterMessageId = null): array;
    /** Input: request_id, version_digest, text_profile, text_sha256, start_offset, end_offset, quote, body. */
    public function createThread(TenantContext $context, int $documentId, int $revisionId, array $input): array;
    /** Input: request_id, version_digest, text_profile, text_sha256, thread_id, expected_thread_revision, body. */
    public function reply(TenantContext $context, int $documentId, int $revisionId, array $input): array;
    /** Input: request_id, version_digest, text_profile, text_sha256, thread_id, expected_thread_revision. */
    public function resolve(TenantContext $context, int $documentId, int $revisionId, array $input): array;
    public function reopen(TenantContext $context, int $documentId, int $revisionId, array $input): array;
}
