<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\File\Contract;

/** Document must register this original object against its locked accepted plan in the caller transaction. */
final readonly class AcceptedReferenceTransfer
{
    public function __construct(public int $tenantId, public int $documentId, public string $captureHash) {}
}
