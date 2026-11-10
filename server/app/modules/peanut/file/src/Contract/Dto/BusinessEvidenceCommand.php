<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\File\Contract\Dto;

/** Business owns the command digest, including purpose, target, actor and inspected byte metadata. */
final readonly class BusinessEvidenceCommand
{
    public function __construct(public string $moduleKey, public string $commandKey, public string $inputDigest)
    {
        if (preg_match('/^[a-z][a-z0-9_]*\.[a-z][a-z0-9_.-]*$/D', $moduleKey) !== 1 || strlen($moduleKey) > 128
            || in_array($moduleKey, ['peanut.file', 'peanut.document'], true)
            || preg_match('/^[\x21-\x7e]{1,128}$/D', $commandKey) !== 1
            || preg_match('/^[a-f0-9]{64}$/D', $inputDigest) !== 1) {
            throw new \InvalidArgumentException('Invalid business evidence command');
        }
    }
}
