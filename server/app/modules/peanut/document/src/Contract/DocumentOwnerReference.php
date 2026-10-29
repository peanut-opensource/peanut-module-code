<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Document\Contract;

/** Business identity, never an ORM class or table supplied by a caller. */
final readonly class DocumentOwnerReference
{
    public function __construct(public string $moduleKey, public string $objectType, public string $objectId, public string $slot)
    {
        if (preg_match('/^[a-z][a-z0-9-]*\.[a-z][a-z0-9-]*$/D', $moduleKey) !== 1
            || preg_match('/^[a-z][a-z0-9_-]{0,63}$/D', $objectType) !== 1
            || preg_match('/^[A-Za-z0-9_-]{1,128}$/D', $objectId) !== 1
            || preg_match('/^[a-z][a-z0-9_-]{0,63}$/D', $slot) !== 1) {
            throw new \InvalidArgumentException('DOCUMENT_OWNER_REFERENCE_INVALID');
        }
    }
}
