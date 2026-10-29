<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Document\Contract;

/** Optional business-owned publication boundary; never grants ordinary Document/history/File access. */
interface DocumentPublicationOwnerPolicy
{
    /** Lock and authorize the current published owner in the trusted tenant, then return its server-persisted Save binding. */
    public function publishedBinding(int $tenantId, DocumentOwnerReference $owner): array;
}
