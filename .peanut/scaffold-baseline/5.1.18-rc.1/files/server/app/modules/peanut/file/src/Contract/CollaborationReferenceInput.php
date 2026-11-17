<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\File\Contract;

/** A selected reference reservation. Current Document/File authorization still comes from the caller's transaction. */
final readonly class CollaborationReferenceInput
{
    public function __construct(
        public int $tenantId,
        public int $documentId,
        public string $reservationKey,
        public array $fileKeys,
        public string $grantDigest,
    ) {
        if ($tenantId < 1 || $documentId < 1 || preg_match('/^[0-9a-f]{64}$/D', $reservationKey) !== 1
            || preg_match('/^[0-9a-f]{64}$/D', $grantDigest) !== 1 || !array_is_list($fileKeys) || count($fileKeys) > 1000
            || count(array_unique($fileKeys, SORT_REGULAR)) !== count($fileKeys)) {
            throw new \InvalidArgumentException('FILE_COLLABORATION_REFERENCE_INVALID');
        }
        foreach ($fileKeys as $key) {
            if (!is_string($key) || preg_match('/^file_[0-9a-f]{32}$/D', $key) !== 1) {
                throw new \InvalidArgumentException('FILE_COLLABORATION_REFERENCE_INVALID');
            }
        }
    }
}
