<?php

declare(strict_types=1);

namespace app\common\contract\audit;

final readonly class AuditResource
{
    public function __construct(
        public string $type,
        public string $id,
    ) {
        if (trim($type) === '' || trim($id) === '') {
            throw new \InvalidArgumentException('AUDIT_RESOURCE_INVALID');
        }
    }
}
