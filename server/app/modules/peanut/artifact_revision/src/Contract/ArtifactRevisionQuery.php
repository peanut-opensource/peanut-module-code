<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\ArtifactRevision\Contract;

use PeanutAdmin\Modules\ArtifactRevision\Contract\Dto\ArtifactRevisionSnapshot;

/** Read-only fixed revision boundary. The caller must authorize the business object first. */
interface ArtifactRevisionQuery
{
    public function snapshot(int $tenantId, string $artifactType, string $artifactKey, string $revisionKey, bool $forUpdate = false): ?ArtifactRevisionSnapshot;
}
