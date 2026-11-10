<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\File\Contract\Dto;

/** File-produced metadata. prepared has no key; reserved is not proof of provider success. */
final readonly class BusinessEvidenceFile
{
    public function __construct(
        public ?string $fileKey,
        public string $state,
        public string $originalName,
        public string $verifiedMimeType,
        public int $sizeBytes,
        public string $sha256,
    ) {}
}
