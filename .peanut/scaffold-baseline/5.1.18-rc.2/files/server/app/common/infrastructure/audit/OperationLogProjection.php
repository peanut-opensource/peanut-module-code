<?php

declare(strict_types=1);

namespace app\common\infrastructure\audit;

use app\common\policy\audit\RedactionPolicy;
use app\common\contract\audit\AuditEvent;
use app\common\execution\CurrentExecutionContext;
use app\common\model\log\OperationLog;

final class OperationLogProjection
{
    public function __construct(
        private readonly ?CurrentExecutionContext $execution,
    ) {}

    public function append(AuditEvent $event): void
    {
        if ($event->projection !== AuditEvent::OPERATION_LOG || $event->tenantId === null) {
            throw new \InvalidArgumentException('AUDIT_OPERATION_LOG_EVENT_INVALID');
        }
        if ($this->execution === null
            || $this->execution->tenantId() !== $event->tenantId
            || !hash_equals($this->execution->requestId(), $event->trace->requestId)) {
            throw new \DomainException('AUDIT_OPERATION_LOG_CONTEXT_MISMATCH');
        }
        $metadata = RedactionPolicy::sanitize($event->metadata);
        $params = $metadata['params'] ?? [];
        OperationLog::create([
            'tenant_id' => $event->tenantId,
            'admin_id' => (int) ($metadata['admin_id'] ?? 0),
            'username' => (string) ($metadata['username'] ?? ''),
            'ip' => (string) ($metadata['ip'] ?? $event->trace->ipAddress ?? ''),
            'uri' => strtolower(trim((string) ($metadata['uri'] ?? ''), '/')),
            'method' => strtoupper((string) ($metadata['method'] ?? '')),
            'request_id' => $event->trace->requestId,
            'params' => RedactionPolicy::encode($params),
            'create_time' => time(),
        ]);
    }
}
