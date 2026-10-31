<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Collaboration\Contract;

use PeanutAdmin\Kernel\Auth\TenantContext;

/** Authorized transport metadata; an owner resolver never grants socket access itself. */
interface CollaborationTransports
{
    /**
     * Resolve a configured endpoint after current actor/Document authorization.
     * Write resolution enables collaboration; read resolution requires it already enabled.
     * No socket credential or persistence receipt is issued.
     *
     * @param 'read'|'write' $mode
     * @return array{document_id: int, mode: 'read'|'write', protocol: 2, wss_url: string, document_name: string}
     */
    public function transport(TenantContext $context, int $documentId, string $mode): array;

    /**
     * Write transport fixed to the caller's compiled business owner.
     * Resolve on each mount/identity change; the result is not a reusable grant.
     *
     * @return array{document_id: int, mode: 'write', protocol: 2, wss_url: string, document_name: string, owner: array{module_key: string, object_type: string, object_id: string, slot: string}, authorization_revision: string}
     */
    public function ownerTransport(TenantContext $context, int $documentId, \PeanutAdmin\Modules\Document\Contract\DocumentOwnerReference $owner): array;
}
