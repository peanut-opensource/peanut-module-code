<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\ArtifactRevision\Contract\Dto;

/** An integrity-checked, finalized immutable envelope; no ORM or mutable storage handle. */
final readonly class ArtifactRevisionSnapshot
{
    public function __construct(
        public string $revisionKey,
        public string $sha256,
        public array $envelope,
        public int $createdByMemberId,
    ) {
        if (preg_match('/^revision_[0-9a-f]{32}$/D', $revisionKey) !== 1
            || preg_match('/^[0-9a-f]{64}$/D', $sha256) !== 1 || $createdByMemberId < 1) {
            throw new \InvalidArgumentException('ARTIFACT_SNAPSHOT_INVALID');
        }
    }
}
