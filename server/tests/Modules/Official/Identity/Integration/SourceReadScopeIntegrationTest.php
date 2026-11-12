<?php

declare(strict_types=1);

namespace app\tests\Modules\Official\Identity\Integration;

use PeanutAdmin\Modules\Identity\access\SourceRead\SourceReadCapability;
use PeanutAdmin\Modules\Identity\access\SourceRead\SourceReadCapabilityRegistry;
use PeanutAdmin\Modules\Identity\access\SourceRead\SourceReadGrantAdministrationService;
use PeanutAdmin\Modules\Identity\access\SourceRead\ThinkPhpReadScopeAuthority;
use PDO;
use PeanutAdmin\DataPermission\Constraint\ColumnReference;
use PeanutAdmin\DataPermission\Constraint\ThinkPhpQueryConstraintApplier;
use PeanutAdmin\DataPermission\Exception\DataAuthorizationException;
use PeanutAdmin\Modules\Identity\Audit\AuditService;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Auth\ValidatedTenantSession;
use PeanutAdmin\Kernel\Authorization\RevisionPermissionCache;
use PeanutAdmin\Kernel\Authorization\TenantAuthorizationEvaluator;
use PeanutAdmin\Modules\Identity\Authorization\ThinkPhpTenantAuthorizationRepository;
use PeanutAdmin\Kernel\Module\CompiledModuleRegistry;
use PeanutAdmin\Kernel\Module\ManifestLoader;
use PeanutAdmin\Modules\Identity\Module\ModuleAvailabilityService;
use PeanutAdmin\Modules\Identity\Module\TenantEntitlementService;
use PeanutAdmin\Modules\Identity\Contract\TenantAuthorizationQuery;
use PeanutAdmin\Modules\Identity\Membership\Query\ThinkPhpTenantMemberDirectory;
use PHPUnit\Framework\TestCase;
use think\App;
use think\db\Query;
use think\db\Raw;
use think\facade\Db;

require_once dirname(__DIR__, 4) . '/Support/ThinkPhpTestConnection.php';
require_once dirname(__DIR__, 4) . '/Support/RegisteredMysqlTestResource.php';
require_once dirname(__DIR__, 4) . '/Support/IdentityMysqlFixture.php';

final class SourceReadScopeIntegrationTest extends TestCase
{
    private bool $createdDatabase = false;
    private PDO $database;
    private CompiledModuleRegistry $modules;

    protected function setUp(): void
    {
        // 必须先核验登记端点、精确库名、当前候选和租约；已有非空库绝不清空。
        [$this->database, $this->createdDatabase] = \RegisteredMysqlTestResource::openEmptyDatabase(
            $this->testDatabase(),
        );
        $serverRoot = dirname(__DIR__, 5);
        $app = \think\Container::getInstance();
        $cache = require $serverRoot . '/config/cache.php';
        if (!is_array($cache)) {
            throw new \RuntimeException('The backend cache configuration is invalid.');
        }
        $app->config->set($cache, 'cache');
        $app->cache->clear();
        \ThinkPhpTestConnection::fromPdo($this->database);
        \IdentityMysqlFixture::install($this->database);
        $identityManifest = (new ManifestLoader())->load($serverRoot . '/app/modules/peanut/identity');
        $this->modules = new CompiledModuleRegistry(
            [$identityManifest],
            [],
            [],
            [],
            hash('sha256', $identityManifest->digest),
        );
        $this->database->exec(<<<'SQL'
CREATE TABLE `pa_scope_order` (
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  `id` BIGINT UNSIGNED NOT NULL,
  `amount` INT UNSIGNED NOT NULL,
  `status` VARCHAR(16) NOT NULL,
  PRIMARY KEY (`tenant_id`, `id`)
) ENGINE=InnoDB;
CREATE TABLE `pa_scope_order_line` (
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  `id` BIGINT UNSIGNED NOT NULL,
  `order_id` BIGINT UNSIGNED NOT NULL,
  `quantity` INT UNSIGNED NOT NULL,
  PRIMARY KEY (`tenant_id`, `id`),
  KEY `idx_scope_line_order` (`tenant_id`, `order_id`)
) ENGINE=InnoDB;
SQL);
        $this->fixtures();
        $statement = $this->database->prepare(
            'UPDATE pa_module_installation SET manifest_digest=?,installed_version=? WHERE module_key=?',
        );
        $statement->execute([$identityManifest->digest, $identityManifest->data['version'], 'peanut.identity']);
    }

    protected function tearDown(): void
    {
        if (isset($this->database)) {
            \RegisteredMysqlTestResource::cleanup($this->database, $this->testDatabase(), $this->createdDatabase);
            unset($this->database);
        }
    }

    public function testServerIssuedReadScopePreservesSourceLimitsAcrossQueryShapesAndRevocation(): void
    {
        $registry = new SourceReadCapabilityRegistry([
            new SourceReadCapability(
                'fixture.order-summary',
                'aggregate',
                'peanut.identity',
                'core.role.read',
                'core.role.data-policy.manage',
                ['id', 'amount', 'status'],
            ),
        ]);
        $entitlements = new TenantEntitlementService($this->modules);
        $repository = new ThinkPhpTenantAuthorizationRepository($entitlements,
            new TenantAuthorizationQuery(new ThinkPhpTenantMemberDirectory(), $this->modules));
        $permissions = new TenantAuthorizationEvaluator($repository, new RevisionPermissionCache());
        $modules = new ModuleAvailabilityService($this->modules, $entitlements);
        $grants = new SourceReadGrantAdministrationService(
            $registry,
            $permissions,
            $modules,
            new AuditService(),
        );
        $authority = new ThinkPhpReadScopeAuthority($registry, $permissions, $repository, $modules);
        $receiver = $this->context(101, 501, 1501, 'receiver');
        $sourceA = $this->context(202, 502, 1502, 'source-a');
        $sourceB = $this->context(303, 503, 1503, 'source-b');

        $allowA = $grants->put(
            $sourceA,
            101,
            'fixture.order-summary',
            'aggregate',
            'allow',
            null,
            ['status', 'amount', 'id'],
        );
        $grants->put($sourceA, 101, 'fixture.order-summary', 'aggregate', 'deny', 7, []);
        $grants->put($sourceB, 101, 'fixture.order-summary', 'aggregate', 'allow', 1, ['amount', 'id']);
        self::assertSame(1, $allowA['revision']);
        $persistedAllow = $this->database->query(
            "SELECT action,effect,fields_json,revision FROM pa_source_read_grant WHERE id={$allowA['id']}",
        )->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($persistedAllow);
        self::assertSame('aggregate', $persistedAllow['action']);
        self::assertSame('allow', $persistedAllow['effect']);
        self::assertSame(['amount', 'id', 'status'], json_decode($persistedAllow['fields_json'], true, flags: JSON_THROW_ON_ERROR));
        self::assertSame(1, (int) $persistedAllow['revision']);

        $scope = $authority->authorize($receiver, 'fixture.order-summary', 'aggregate', [202, 303]);
        self::assertSame(101, $scope->actor->tenantId, 'source scope must not change the actor Tenant');
        self::assertSame([202, 303], $scope->sourceTenantIds());
        $scope->assertFields(['id', 'amount']);
        $this->expectAuthorizationCode(
            'AUTHZ_READ_FIELDS_DENIED',
            static fn() => $scope->assertFields(['status']),
        );

        $constraint = $scope->constraint(new ColumnReference('o.tenant_id'), new ColumnReference('o.id'));
        $applier = new ThinkPhpQueryConstraintApplier();
        $joined = Db::table('pa_scope_order')->alias('o')
            ->join('pa_scope_order_line l', 'l.tenant_id=o.tenant_id AND l.order_id=o.id')
            ->field('o.tenant_id,o.id,o.amount,l.quantity')->order('o.tenant_id')->order('o.id');
        $applier->apply($joined, $constraint);
        self::assertSame(
            [
                ['tenant_id' => 202, 'id' => 1, 'amount' => 10, 'quantity' => 2],
                ['tenant_id' => 303, 'id' => 1, 'amount' => 20, 'quantity' => 3],
            ],
            array_map($this->ints(...), $joined->select()->toArray()),
        );

        $countQuery = Db::table('pa_scope_order')->alias('o');
        $applier->apply($countQuery, $constraint);
        self::assertSame(2, (int) (clone $countQuery)->count());
        self::assertSame(
            [['tenant_id' => 202, 'id' => 1]],
            array_map(
                $this->ints(...),
                $countQuery->field('o.tenant_id,o.id')->order('o.tenant_id')->order('o.id')->page(1, 1)->select()->toArray(),
            ),
        );

        $aggregate = Db::table('pa_scope_order')->alias('o');
        $applier->apply($aggregate, $constraint);
        self::assertSame(30, (int) $aggregate->sum('o.amount'));

        $outer = Db::table('pa_scope_order')->alias('o');
        $applier->apply($outer, $constraint);
        $subquery = (new Query($outer->getConnection()))
            ->table('pa_scope_order_line')->alias('l')->fieldRaw('1')
            ->whereRaw('l.tenant_id=o.tenant_id AND l.order_id=o.id');
        $applier->apply(
            $subquery,
            $scope->constraint(new ColumnReference('l.tenant_id'), new ColumnReference('l.order_id')),
        );
        $subquery->parseOptions();
        $sql = $outer->getConnection()->getBuilder()->select($subquery);
        $outer->whereExists(new Raw($sql, $subquery->getBind(false)));
        self::assertSame(2, (int) $outer->count(), 'the correlated subquery must retain the same source scope');

        $this->expectAuthorizationCode(
            'AUTHZ_READ_SOURCE_REQUIRED',
            fn() => $authority->authorize($receiver, 'fixture.order-summary', 'aggregate'),
        );
        $this->expectAuthorizationCode(
            'AUTHZ_READ_SOURCE_DENIED',
            fn() => $authority->authorize($receiver, 'fixture.order-summary', 'aggregate', [101]),
        );
        $this->expectAuthorizationCode(
            'AUTHZ_READ_CAPABILITY_UNKNOWN',
            fn() => $authority->authorize($receiver, 'fixture.unknown', 'aggregate', [202, 303]),
        );

        $grants->put($sourceB, 101, 'fixture.order-summary', 'aggregate', 'deny', null, []);
        $this->expectAuthorizationCode(
            'AUTHZ_READ_SOURCE_DENIED',
            fn() => $authority->authorize($receiver, 'fixture.order-summary', 'aggregate', [303]),
        );

        self::assertSame(2, $grants->revoke($sourceA, $allowA['id'], 1));
        $this->expectAuthorizationFailure(fn() => $authority->assertCurrent($scope));
        $persistedRevocation = $this->database->query(
            "SELECT status,revision FROM pa_source_read_grant WHERE id={$allowA['id']}",
        )->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($persistedRevocation);
        self::assertSame('revoked', $persistedRevocation['status']);
        self::assertSame(2, (int) $persistedRevocation['revision']);
        self::assertSame(
            5,
            (int) $this->database->query(
                "SELECT COUNT(*) FROM pa_tenant_audit_event WHERE event_type IN ('tenant.source-read-grant.changed','tenant.source-read-grant.revoked')",
            )->fetchColumn(),
        );
    }

    /** 精确采用已登记且由本任务持有的库；旧显式参数不得指向另一数据库。 */
    private function testDatabase(): string
    {
        $name = \RegisteredMysqlTestResource::configuredDatabaseName();
        $selected = getenv('PEANUT_SCOPE_TEST_DATABASE');
        if ($selected !== false && $selected !== '' && !hash_equals($name, $selected)) {
            throw new \RuntimeException('SOURCE_READ_TEST_DATABASE_INVALID');
        }
        return $name;
    }

    public function testDistinctModuleEntitlementsAndObjectFieldProjectionUseTheRealGrantStore(): void
    {
        $registry = new SourceReadCapabilityRegistry([
            new SourceReadCapability(
                'fixture.order-summary',
                'aggregate',
                'official.inventory',
                'core.role.read',
                'core.role.data-policy.manage',
                ['id', 'amount', 'status'],
                'official.summary',
            ),
        ]);
        $entitlements = new TenantEntitlementService($this->modules);
        $repository = new ThinkPhpTenantAuthorizationRepository($entitlements,
            new TenantAuthorizationQuery(new ThinkPhpTenantMemberDirectory(), $this->modules));
        $permissions = new TenantAuthorizationEvaluator($repository, new RevisionPermissionCache());
        // 这一组只替换模块状态来源，实际授权存储、租户、权限、SQL 和撤销仍用真实 MySQL。
        $moduleCalls = [];
        $modules = $this->createMock(\PeanutAdmin\Kernel\Module\ModuleAvailability::class);
        $modules->expects(self::atLeastOnce())->method('assertAvailable')->willReturnCallback(
            function ($scope, $moduleKey) use (&$moduleCalls): void {
                $moduleCalls[] = [$scope->tenantId(), $moduleKey];
                $expected = $scope->tenantId() === 101 ? 'official.summary' : 'official.inventory';
                self::assertSame($expected, $moduleKey, 'Source provider and recipient feature must be checked separately');
            },
        );
        $grants = new SourceReadGrantAdministrationService($registry, $permissions, $modules, new AuditService());
        $authority = new ThinkPhpReadScopeAuthority($registry, $permissions, $repository, $modules);
        $source = $this->context(202, 502, 1502, 'source-a');
        $receiver = $this->context(101, 501, 1501, 'receiver');
        $first = $grants->put($source, 101, 'fixture.order-summary', 'aggregate', 'allow', 1, ['id', 'amount']);
        $grants->put($source, 101, 'fixture.order-summary', 'aggregate', 'allow', 7, ['id', 'status']);
        $scope = $authority->authorize($receiver, 'fixture.order-summary', 'aggregate', [202], ['id', 'amount']);
        self::assertSame(['amount', 'id'], $scope->requestedFields);
        self::assertSame([1], $scope->sources[0]->objectIds);
        $query = Db::table('pa_scope_order')->alias('o')->field('o.id,o.amount')->order('o.id');
        (new ThinkPhpQueryConstraintApplier())->apply($query, $scope->constraint(new ColumnReference('o.tenant_id'), new ColumnReference('o.id')));
        self::assertSame([['id' => 1, 'amount' => 10]], array_map($this->ints(...), $query->select()->toArray()));
        $other = $authority->authorize($receiver, 'fixture.order-summary', 'aggregate', [202], ['status']);
        self::assertSame([7], $other->sources[0]->objectIds);
        $this->expectAuthorizationCode('AUTHZ_READ_FIELDS_DENIED', fn() => $authority->authorize($receiver, 'fixture.order-summary', 'aggregate', [202], ['amount', 'status']));
        $authority->assertCurrent($scope);
        self::assertContains([101, 'official.summary'], $moduleCalls);
        self::assertContains([202, 'official.inventory'], $moduleCalls);
        $grants->revoke($source, $first['id'], 1);
        $this->expectAuthorizationFailure(fn() => $authority->assertCurrent($scope));
    }

    private function fixtures(): void
    {
        $this->database->exec(<<<'SQL'
INSERT INTO pa_tenant (id,code,name,display_name,status,activated_at,created_at,updated_at) VALUES
 (101,'receiver','Receiver','Receiver','active',UTC_TIMESTAMP(3),UTC_TIMESTAMP(3),UTC_TIMESTAMP(3)),
 (202,'source-a','Source A','Source A','active',UTC_TIMESTAMP(3),UTC_TIMESTAMP(3),UTC_TIMESTAMP(3)),
 (303,'source-b','Source B','Source B','active',UTC_TIMESTAMP(3),UTC_TIMESTAMP(3),UTC_TIMESTAMP(3));
INSERT INTO pa_account (id,display_name,created_at,updated_at) VALUES
 (1501,'Receiver Owner',UTC_TIMESTAMP(3),UTC_TIMESTAMP(3)),
 (1502,'Source A Owner',UTC_TIMESTAMP(3),UTC_TIMESTAMP(3)),
 (1503,'Source B Owner',UTC_TIMESTAMP(3),UTC_TIMESTAMP(3));
INSERT INTO pa_tenant_member (id,tenant_id,account_id,display_name,status,joined_at,created_at,updated_at) VALUES
 (501,101,1501,'Receiver Owner','active',UTC_TIMESTAMP(3),UTC_TIMESTAMP(3),UTC_TIMESTAMP(3)),
 (502,202,1502,'Source A Owner','active',UTC_TIMESTAMP(3),UTC_TIMESTAMP(3),UTC_TIMESTAMP(3)),
 (503,303,1503,'Source B Owner','active',UTC_TIMESTAMP(3),UTC_TIMESTAMP(3),UTC_TIMESTAMP(3));
INSERT INTO pa_role (id,tenant_id,`key`,name,is_builtin,status,created_at,updated_at) VALUES
 (601,101,'core.tenant-owner','Owner',1,'active',UTC_TIMESTAMP(3),UTC_TIMESTAMP(3)),
 (602,202,'core.tenant-owner','Owner',1,'active',UTC_TIMESTAMP(3),UTC_TIMESTAMP(3)),
 (603,303,'core.tenant-owner','Owner',1,'active',UTC_TIMESTAMP(3),UTC_TIMESTAMP(3));
INSERT INTO pa_member_role (tenant_id,tenant_member_id,role_id,assigned_at) VALUES
 (101,501,601,UTC_TIMESTAMP(3)),(202,502,602,UTC_TIMESTAMP(3)),(303,503,603,UTC_TIMESTAMP(3));
INSERT INTO pa_module_installation (module_key,installed_version,manifest_schema_version,manifest_digest,status,revision,installed_at,activated_at,created_at,updated_at)
 VALUES ('peanut.identity','4.0.0-dev',1,REPEAT('a',64),'active',1,UTC_TIMESTAMP(3),UTC_TIMESTAMP(3),UTC_TIMESTAMP(3),UTC_TIMESTAMP(3));
INSERT INTO pa_scope_order (tenant_id,id,amount,status) VALUES
 (101,1,999,'local'),(202,1,10,'open'),(202,7,70,'denied'),(303,1,20,'open'),(303,2,30,'not-granted');
INSERT INTO pa_scope_order_line (tenant_id,id,order_id,quantity) VALUES
 (101,1,1,9),(202,1,1,2),(202,7,7,7),(303,1,1,3),(303,2,2,4);
SQL);
    }

    private function context(int $tenantId, int $memberId, int $accountId, string $request): TenantContext
    {
        return TenantContext::fromValidatedSession(new ValidatedTenantSession(
            $memberId,
            'scope-session-' . $memberId,
            $tenantId,
            $accountId,
            $memberId,
            'admin-web',
            new \DateTimeImmutable('2031-01-01T00:00:00Z'),
            1,
            1,
        ), 'scope-' . $request);
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function ints(array $row): array
    {
        foreach (['tenant_id', 'id', 'amount', 'quantity'] as $field) {
            if (array_key_exists($field, $row)) {
                $row[$field] = (int) $row[$field];
            }
        }
        return $row;
    }

    private function expectAuthorizationCode(string $code, callable $operation): void
    {
        try {
            $operation();
        } catch (DataAuthorizationException $exception) {
            self::assertSame($code, $exception->errorCode);
            return;
        }
        self::fail("Expected authorization failure {$code}.");
    }

    private function expectAuthorizationFailure(callable $operation): void
    {
        try {
            $operation();
        } catch (DataAuthorizationException) {
            self::assertTrue(true);
            return;
        }
        self::fail('Expected the stale or revoked scope to fail closed.');
    }
}
