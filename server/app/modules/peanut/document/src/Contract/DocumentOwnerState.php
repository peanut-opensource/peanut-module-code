<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Document\Contract;

/** Resolved exclusively by a compiled business provider. */
final readonly class DocumentOwnerState
{
    public function __construct(public int $tenantId, public DocumentOwnerReference $reference, public bool $archived, public string $revision)
    {
        if ($tenantId < 1 || $revision === '') {
            throw new \InvalidArgumentException('DOCUMENT_OWNER_STATE_INVALID');
        }
    }
}
