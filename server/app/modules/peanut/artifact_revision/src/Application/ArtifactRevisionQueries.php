<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\ArtifactRevision\Application;

use PeanutAdmin\Modules\ArtifactRevision\Contract\ArtifactRevisionQuery;
use PeanutAdmin\Modules\ArtifactRevision\Contract\Dto\ArtifactRevisionSnapshot;
use PeanutAdmin\Modules\ArtifactRevision\Persistence\ThinkPhpArtifactRevisionRepository;

final readonly class ArtifactRevisionQueries implements ArtifactRevisionQuery
{
    public function __construct(private ThinkPhpArtifactRevisionRepository $revisions) {}

    public function snapshot(int $tenantId, string $artifactType, string $artifactKey, string $revisionKey, bool $forUpdate = false): ?ArtifactRevisionSnapshot
    {
        if ($tenantId < 1 || preg_match('/^[a-z][a-z0-9]*(?:[.-][a-z0-9]+)*$/D', $artifactType) !== 1 || strlen($artifactType) > 64
            || preg_match('/^[\x21-\x7E]+$/D', $artifactKey) !== 1 || strlen($artifactKey) > 128
            || preg_match('/^revision_[0-9a-f]{32}$/D', $revisionKey) !== 1) {
            throw new \InvalidArgumentException('ARTIFACT_SNAPSHOT_IDENTITY_INVALID');
        }
        $revision = $this->revisions->revision($tenantId, $artifactType, $artifactKey, $revisionKey, $forUpdate);
        if ($revision === null || !$revision->isFinalized()) {
            return null;
        }
        $revision->assertEnvelopeIntegrity();
        return new ArtifactRevisionSnapshot($revision->revisionKey, $revision->canonicalEnvelopeSha256, $revision->canonicalEnvelope(), $revision->createdByMemberId);
    }
}
