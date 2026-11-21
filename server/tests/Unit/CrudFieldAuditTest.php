<?php

declare(strict_types=1);

namespace tests\Unit;

use app\common\contract\audit\AuditResource;
use app\common\services\AbstractCrudService;
use app\common\services\audit\AuditContractHost;
use DateTimeImmutable;
use PDO;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Auth\ValidatedTenantSession;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use think\App;
use think\db\BaseQuery;
use think\Model;

final class CrudFieldAuditTest extends TestCase
{
    protected function setUp(): void
    {
        if (!class_exists(\PeanutAdmin\Modules\Identity\Audit\AuditService::class)) {
            self::markTestSkipped('Requires official module Identity.');
        }
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testCrudEditPersistsSafeScopedHistoryAndRollsBackOnAuditFailure(): void
    {
        $root = getenv('PEANUT_TEST_APPLICATION_ROOT') ?: dirname(__DIR__, 3);
        require_once $root . '/server/vendor/topthink/framework/src/helper.php';
        if (!class_exists(\ThinkPhpTestConnection::class, false)) {
            require_once dirname(__DIR__) . '/Support/ThinkPhpTestConnection.php';
        }
        $app = new App($root . '/.local/tmp/crud-field-audit');
        $audit = new AuditContractHost(null);
        $app->instance(AuditContractHost::class, $audit);
        $database = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $database->sqliteCreateFunction('UTC_TIMESTAMP', static fn(int $precision): string => '2026-11-21 00:00:00.000', 1);
        \ThinkPhpTestConnection::fromPdo($database);
        $database->exec(<<<'SQL'
            CREATE TABLE pa_crud_audit_fixture (id INTEGER PRIMARY KEY, tenant_id INTEGER, label TEXT, email TEXT, secret TEXT);
            INSERT INTO pa_crud_audit_fixture VALUES (42, 1, 'old', 'old@example.test', 'old-secret'), (43, 2, 'other', '', '');
            CREATE TABLE pa_tenant_audit_event (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, event_type TEXT, action TEXT, outcome TEXT, reason_code TEXT, actor_tenant_id INTEGER, actor_tenant_member_id INTEGER, actor_account_id INTEGER, actor_platform_operator_id INTEGER, actor_type TEXT, target_resource_type TEXT COLLATE NOCASE, target_resource_id TEXT COLLATE NOCASE, boundary_target_type TEXT, boundary_target_id TEXT, target_count INTEGER, target_set_digest TEXT, authorization_basis_json TEXT, request_id TEXT, operation_id TEXT, ip_address TEXT, user_agent_hash TEXT, before_json TEXT, after_json TEXT, metadata_json TEXT, occurred_at TEXT);
            SQL);
        $service = new class extends AbstractCrudService {
            protected function query(TenantContext $context): BaseQuery
            {
                return CrudAuditFixtureRecord::where('tenant_id', $context->tenantId);
            }
            protected function newModel(TenantContext $context): Model { return new CrudAuditFixtureRecord(); }
            protected function assertPermission(TenantContext $context, string $action): void {}
            protected function listFields(): array { return ['id', 'label']; }
            protected function detailFields(): array { return ['id', 'label', 'email', 'secret']; }
            protected function insertFields(): array { return $this->updateFields(); }
            protected function updateFields(): array { return ['label', 'email', 'secret']; }
            protected function diffFields(): array { return $this->updateFields(); }
            protected function maskedFields(): array { return ['email' => 'email']; }
            protected function auditResourceType(): string { return 'fixture.crud'; }
        };
        $context = TenantContext::fromValidatedSession(
            new ValidatedTenantSession(1, 'session', 1, 2, 3, 'web', new DateTimeImmutable(), 1, 1),
            'crud-audit-fixture',
        );
        $update = ['id' => 42, 'label' => 'new', 'email' => 'new@example.test', 'secret' => 'new-secret'];
        self::assertTrue($service->edit($context, $update));
        self::assertTrue($service->edit($context, $update));

        $database->exec("INSERT INTO pa_tenant_audit_event (tenant_id, event_type, target_resource_type, target_resource_id, before_json, after_json, occurred_at)
            VALUES (2, 'crud.field_diff', 'fixture.crud', '42', '{\"label\":\"foreign\"}', '{\"label\":\"hidden\"}', '2026-11-21 00:00:00.000')");
        $audit->recordCrudFieldDiff($context, new AuditResource('FIXTURE.CRUD', '42'), [
            'label' => ['before' => 'different resource', 'after' => 'hidden'],
        ]);
        $audit->recordCrudFieldDiff($context, new AuditResource('fixture.crud', 'ABC'), [
            'payload' => [
                'before' => (object) ['label' => 'old', 'token' => 'hidden before'],
                'after' => (object) ['label' => 'new', 'token' => 'hidden after'],
            ],
        ]);
        self::assertSame([], $audit->crudFieldHistory($context, new AuditResource('fixture.crud', 'abc'), ['payload' => true]));
        $objectHistory = $audit->crudFieldHistory($context, new AuditResource('fixture.crud', 'ABC'), ['payload' => true]);
        self::assertSame(['payload' => ['label' => 'new', 'token' => '******']], $objectHistory[0]['after']);
        $audit->recordCrudFieldDiff($context, new AuditResource('fixture.crud', '42'), [
            'label' => ['before' => 'short', 'after' => str_repeat('x', 61000)],
        ]);
        $history = $service->detail($context, 42)['audit_history'];
        self::assertCount(1, $history);
        self::assertSame(['label' => 'old', 'secret' => '******'], $history[0]['before']);
        self::assertSame(['label' => 'new', 'secret' => '******'], $history[0]['after']);

        $database->exec("CREATE TRIGGER fail_audit BEFORE INSERT ON pa_tenant_audit_event BEGIN SELECT RAISE(ABORT, 'audit_write_failed'); END");
        try {
            $service->edit($context, ['id' => 42, 'label' => 'rolled back']);
            self::fail('The resource write committed without its audit event.');
        } catch (\think\db\exception\PDOException $failure) {
            self::assertStringContainsString('audit_write_failed', $failure->getMessage());
        }
        self::assertSame('new', $database->query('SELECT label FROM pa_crud_audit_fixture WHERE id=42')->fetchColumn());
    }
}

final class CrudAuditFixtureRecord extends Model
{
    protected $name = 'crud_audit_fixture';
    protected $autoWriteTimestamp = false;
}
