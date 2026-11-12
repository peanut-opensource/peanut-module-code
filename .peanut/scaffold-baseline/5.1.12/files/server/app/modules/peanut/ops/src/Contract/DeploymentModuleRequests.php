<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Ops\Contract;

/**
 * Trusted deployment request preparation, not task execution or a permission grant.
 * Implementations retain registered resource, signature, exact-plan and target checks.
 * The internal request row and execute operation are deliberately not part of this port.
 */
interface DeploymentModuleRequests
{
    /** @return array<string, mixed> */
    public function preview(
        string $deliveryResourceId,
        string $targetResourceId,
        string $operation,
        string $packageKey,
        ?string $archiveSha256,
        ?string $signatureKeyId,
    ): array;

    /** @return array<string, mixed> */
    public function prepare(
        string $deliveryResourceId,
        string $targetResourceId,
        string $operation,
        string $packageKey,
        ?string $archiveSha256,
        ?string $signatureKeyId,
        ?string $confirmPlanDigest,
        ?string $submissionDigest = null,
    ): array;
}
