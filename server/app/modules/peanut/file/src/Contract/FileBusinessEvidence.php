<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\File\Contract;

use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Modules\File\Contract\Dto\BusinessEvidenceCommand;
use PeanutAdmin\Modules\File\Contract\Dto\BusinessEvidenceFile;
use PeanutAdmin\Modules\File\Contract\Dto\BusinessEvidenceFact;
use PeanutAdmin\Modules\File\Contract\Dto\UploadFile;

/** Private, non-Document evidence. See README.md#private-business-evidence for the authorization boundary. */
interface FileBusinessEvidence
{
    /** Inspect actual bytes; the HTTP supplied size/MIME/extension are not authoritative. */
    public function inspectEvidence(UploadFile $file): BusinessEvidenceFile;

    /** No outer transaction. Only the reservation creator may write, once, to the durable original key.
     * $authorize locks/revalidates the business command, purpose and scope; it runs before each phase.
     * $bind receives the reserved File metadata in the reservation transaction (before provider I/O).
     * $publish persists the business result/outbox in the same transaction as File's ready state.
     * Callbacks must use the default ThinkPHP connection and throw on denial or a failed CAS.
     * A reserved result means UNKNOWN: query this command; never create another upload to recover it.
     * @param \Closure():void $authorize
     * @param \Closure(BusinessEvidenceFile):void $bind
     * @param \Closure(BusinessEvidenceFile):void $publish
     */
    public function storeEvidence(TenantContext $actor, BusinessEvidenceCommand $command, UploadFile $file, \Closure $authorize, \Closure $bind, \Closure $publish): BusinessEvidenceFile;

    /** No outer transaction. Observe the original key and verified bytes; never repeats a PUT.
     * Null means no committed reservation. Reserved/absent bytes remain UNKNOWN, including an active writer.
     * @param \Closure():void $authorize
     * @param \Closure(BusinessEvidenceFile):void $publish
     */
    public function observeEvidence(TenantContext $actor, BusinessEvidenceCommand $command, \Closure $authorize, \Closure $publish): ?BusinessEvidenceFile;

    /** Requires the business transaction containing the financial fact. The callback locks and authorizes
     * that persisted fact and checks its basis digest and exact file metadata against File's snapshots.
     * File independently authorizes every file. An existing fact cannot change its digest or file set.
     * No release/replacement, contextual permission inheritance, public delivery or Document impersonation.
     * @param \Closure(list<BusinessEvidenceFile>):void $authorizeFact
     */
    public function retainEvidence(TenantContext $actor, BusinessEvidenceFact $fact, \Closure $authorizeFact): void;
}
