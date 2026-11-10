<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\File\Contract\Dto;

/** Identifies one immutable financial fact and its basis, not a mutable upload root. */
final readonly class BusinessEvidenceFact
{
    /** @param list<string> $fileKeys */
    public function __construct(public string $moduleKey, public string $ownerType, public string $ownerKey, public string $basisDigest, public array $fileKeys)
    {
        new BusinessEvidenceCommand($moduleKey, $ownerKey, $basisDigest);
        if (preg_match('/^[a-z][a-z0-9_-]{0,49}$/D', $ownerType) !== 1
            || !array_is_list($fileKeys) || count($fileKeys) > 1000 || $fileKeys === []) {
            throw new \InvalidArgumentException('Invalid evidence fact');
        }
        foreach ($fileKeys as $key) {
            if (!is_string($key) || preg_match('/^file_[a-f0-9]{32}$/D', $key) !== 1) {
                throw new \InvalidArgumentException('Invalid evidence file key');
            }
        }
        if (count(array_unique($fileKeys)) !== count($fileKeys)) {
            throw new \InvalidArgumentException('Duplicate evidence file key');
        }
    }
}
