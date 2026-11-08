<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\File\Contract;

use PeanutAdmin\Kernel\Auth\TenantContext;

/**
 * Verification only: no bytes, URL, transferable grant or task authority is returned.
 * Document must independently verify its native Task binding before and after this check.
 * Background original-source use requires the member's current independent download permission.
 */
interface FileDocumentSourceChecks
{
    public function assertBackgroundDocumentSnapshotReadable(
        TenantContext $context,
        int $documentId,
        string $fileKey,
        string $sha256,
        string $verifiedMimeType,
    ): void;
}
