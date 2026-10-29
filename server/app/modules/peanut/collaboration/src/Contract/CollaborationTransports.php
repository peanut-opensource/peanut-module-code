<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Collaboration\Contract;

use PeanutAdmin\Kernel\Auth\TenantContext;

/** Authorized transport metadata; an owner resolver never grants socket access itself. */
interface CollaborationTransports
{
    /** Returns configured WS/WSS wss_url/document_name after actor authorization; no credential is issued. */
    public function transport(TenantContext $context, int $documentId, string $mode): array;
    /** Write transport fixed to the caller's compiled business owner. */
    public function ownerTransport(TenantContext $context, int $documentId, \PeanutAdmin\Modules\Document\Contract\DocumentOwnerReference $owner): array;
}
