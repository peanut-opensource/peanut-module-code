<?php

declare(strict_types=1);

use app\common\infrastructure\idempotency\ThinkPhpIdempotentCommandExecutor;
use PeanutAdmin\Kernel\Audit\AuditOutcome;
use PeanutAdmin\Kernel\Audit\AuditWriter;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Idempotency\IdempotencyService;
use think\facade\Db;

require __DIR__ . '/NativeAccessFixture.php';

Db::execute('CREATE TABLE business_test_audit (id INTEGER PRIMARY KEY, tenant_id INTEGER, event_type TEXT,
    target TEXT, metadata TEXT)');
Db::execute('CREATE TABLE pa_tenant_idempotency_record (id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL,
    tenant_member_id INTEGER, operation_key TEXT, idempotency_key_hash TEXT, request_hash TEXT, status TEXT,
    expires_at TEXT, created_at TEXT, updated_at TEXT, response_status INTEGER, response_body_json TEXT,
    resource_type TEXT, resource_id TEXT,
    UNIQUE (tenant_id, tenant_member_id, operation_key, idempotency_key_hash))');
$pdo = Db::connect()->getPdo();
$pdo->sqliteCreateFunction('UUID', static function (): string {
    static $sequence = 8000;
    return businessKey(++$sequence);
}, 0);
// Supply Core's schema guard with metadata derived from the actual SQLite table.
$pdo->sqliteCreateFunction('DATABASE', static fn(): string => 'main', 0);
Db::execute("ATTACH DATABASE ':memory:' AS information_schema");
Db::execute('CREATE TABLE information_schema.COLUMNS (TABLE_SCHEMA TEXT, TABLE_NAME TEXT, COLUMN_NAME TEXT)');
foreach (Db::query('PRAGMA table_info(pa_tenant_idempotency_record)') as $column) {
    Db::execute('INSERT INTO information_schema.COLUMNS VALUES (?,?,?)', ['main', 'pa_tenant_idempotency_record', $column['name']]);
}
$audit = new class implements AuditWriter {
    public bool $fail = false;
    public function tenantMember(
        TenantContext $context,
        string $eventType,
        string $action,
        ?string $targetResourceType = null,
        ?string $targetResourceId = null,
        array $metadata = [],
        ?int $targetCount = null,
        ?string $boundaryTargetType = null,
        ?string $boundaryTargetId = null,
        ?string $targetSetDigest = null,
        AuditOutcome $outcome = AuditOutcome::Success,
    ): void {
        Db::table('business_test_audit')->insert([
            'tenant_id' => $context->tenantId, 'event_type' => $eventType, 'target' => $targetResourceId,
            'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR),
        ]);
        if ($this->fail) {
            throw new RuntimeException('injected audit failure');
        }
    }
    public function platform(
        int $operatorId,
        int $accountId,
        string $requestId,
        string $eventType,
        string $action,
        array $metadata = [],
        ?string $targetType = null,
        ?string $targetId = null,
        ?array $before = null,
        ?array $after = null,
        AuditOutcome $outcome = AuditOutcome::Success,
    ): void {
        throw new LogicException('No platform action');
    }
};
$commands = new ThinkPhpIdempotentCommandExecutor(new IdempotencyService());
