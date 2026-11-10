<?php

declare(strict_types=1);

namespace app\common\contract\audit;

final readonly class AuditTrace
{
    public function __construct(
        public string $requestId,
        public ?string $operationId = null,
        public ?string $ipAddress = null,
        public ?string $userAgentHash = null,
    ) {
        if (trim($requestId) === '') {
            throw new \InvalidArgumentException('AUDIT_REQUEST_ID_REQUIRED');
        }
    }
}
