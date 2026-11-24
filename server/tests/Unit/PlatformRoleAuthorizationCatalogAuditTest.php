<?php

declare(strict_types=1);

use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Auth\ValidatedPlatformSession;
use PeanutAdmin\Kernel\Auth\ValidatedTenantSession;
use PeanutAdmin\Kernel\Authorization\Application\AdminAccessException;
use PeanutAdmin\Kernel\Authorization\AuthorizationException;
use PeanutAdmin\Kernel\Authorization\RevisionPermissionCache;
use PeanutAdmin\Kernel\Context\PlatformContext;
use PeanutAdmin\Kernel\Identity\PasswordHasher;
use PeanutAdmin\Kernel\Identity\PasswordPolicy;
use PeanutAdmin\Kernel\Module\CompiledModuleRegistry;
use PeanutAdmin\Kernel\Module\ManifestDocument;
use PeanutAdmin\Kernel\Module\ModuleAvailability;
use PeanutAdmin\Kernel\Platform\Authorization\PlatformAuthorizationEvaluator;
use PeanutAdmin\Modules\Identity\Audit\AuditService;
use PeanutAdmin\Modules\Identity\Authorization\ApiPermissionCatalog;
use PeanutAdmin\Modules\Identity\Authorization\Application\RoleAdminService;
use PeanutAdmin\Modules\Identity\Contract\PlatformRoleAuthorizationPreview;
use PeanutAdmin\Modules\Identity\Module\ModuleAvailabilityService;
use PeanutAdmin\Modules\Identity\Module\TenantEntitlementService;
use PeanutAdmin\Modules\Identity\Platform\Application\PlatformAccessAdminService;
use PeanutAdmin\Modules\Identity\Platform\Authorization\ThinkPhpPlatformAuthorizationRepository;
use PeanutAdmin\Modules\Identity\Policy\DemoAccountPolicy;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use think\App;
use think\facade\Db;

require_once dirname(__DIR__) . '/Support/ThinkPhpTestConnection.php';
require_once dirname(__DIR__) . '/Support/PlatformRoleAuthorizationCatalogAuditFixture.php';

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class PlatformRoleAuthorizationCatalogAuditTest extends TestCase
{
    private PDO $database;
    private PlatformAuthorizationEvaluator $authorization;
    private PlatformRoleAuthorizationPreview $preview;
    private ModuleAvailability $modules;
    private ?string $temporaryDirectory = null;

    protected function setUp(): void
    {
        $root = dirname(__DIR__, 3);
        self::assertSame(
            $root . '/server/app/modules/peanut/identity/src/Contract/PlatformRoleAuthorizationPreview.php',
            (new ReflectionClass(PlatformRoleAuthorizationPreview::class))->getFileName(),
        );
        require_once $root . '/server/vendor/topthink/framework/src/helper.php';
        $temporary = $root . '/.local/tmp/platform-authorization-catalog-' . bin2hex(random_bytes(6));
        if (!mkdir($temporary, 0700, true)) {
            throw new RuntimeException('Could not create the platform authorization catalog test directory.');
        }
        $this->temporaryDirectory = $temporary;
        $app = new App($temporary);
        $app->config->set(['default' => 'file', 'stores' => ['file' => ['type' => 'File', 'path' => $temporary . '/cache/']]], 'cache');
        $this->database = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        ThinkPhpTestConnection::fromPdo($this->database);
        PlatformRoleAuthorizationCatalogAuditFixture::install($this->database);

        $roles = new ThinkPhpPlatformAuthorizationRepository();
        $this->authorization = new PlatformAuthorizationEvaluator($roles, new RevisionPermissionCache());
        $this->preview = new PlatformRoleAuthorizationPreview(
            $this->authorization,
            $roles,
            new ApiPermissionCatalog(),
            new DemoAccountPolicy(false, []),
        );
        $this->modules = $this->createStub(ModuleAvailability::class);
    }

    protected function tearDown(): void
    {
        if ($this->temporaryDirectory !== null) {
            $children = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($this->temporaryDirectory, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($children as $child) {
                if ($child->isDir() && !$child->isLink()) {
                    rmdir($child->getPathname());
                } else {
                    unlink($child->getPathname());
                }
            }
            rmdir($this->temporaryDirectory);
        }
        parent::tearDown();
    }

    public function testUnassignedRolesHaveExplicitCoverageAndRepeatedAuditsLeaveAllTablesUnchanged(): void
    {
        $candidate = $this->candidate();
        $candidate['role_ids'][] = '502';
        $candidate['role_assignments'][] = ['role_id' => '502', 'expected_revision' => '3', 'node_keys' => []];
        $before = $this->snapshot();
        $result = $this->audit($candidate);
        self::assertTrue($result['equivalent']);
        self::assertSame(7, $result['catalog_total']);
        self::assertFalse($result['scope_covers_catalog']);
        self::assertSame([], $result['unresolved']);
        self::assertSame(['501', '502'], array_column($result['roles'], 'role_id'));
        self::assertSame([0, 0], array_column($result['roles'], 'member_count'));
        self::assertSame([7, 3], array_column($result['roles'], 'observed_revision'));
        self::assertSame([7, 3], array_column($result['roles'], 'expected_revision'));
        self::assertTrue($result['roles'][0]['relation_equal']);
        self::assertTrue($result['roles'][0]['capability_equal']);
        self::assertSame(['platform.fixture.prerequisite'], $result['roles'][0]['non_http_permission_keys']);
        self::assertSame(['platform.fixture.prerequisite'], $result['roles'][0]['prerequisite_permission_keys']);
        self::assertTrue($result['roles'][1]['relation_equal']);
        self::assertSame($result, $this->audit($candidate));
        self::assertSame($before, $this->snapshot());
    }

    public function testMissingClearedUnknownInactiveAndStaleRolesHaveDistinctResults(): void
    {
        $this->database->exec("INSERT INTO pa_platform_role (id,\"key\",status) VALUES (503,'platform.fixture.retired-role','archived')");
        $candidate = $this->candidate();
        $candidate['role_ids'] = [504, 503, 502, 501];
        $candidate['role_assignments'][0]['expected_revision'] = 6;
        $candidate['role_assignments'][] = ['role_id' => '503', 'expected_revision' => 1, 'node_keys' => []];
        $candidate['role_assignments'][] = ['role_id' => '504', 'expected_revision' => 1, 'node_keys' => []];
        $candidate['role_assignments'][] = ['role_id' => '401', 'expected_revision' => 1, 'node_keys' => []];
        $result = $this->audit($candidate);
        self::assertFalse($result['equivalent']);
        self::assertFalse($result['roles'][0]['relation_equal']);
        self::assertFalse($result['roles'][0]['capability_equal']);
        self::assertSame(['added' => [], 'removed' => []], $result['roles'][0]['relation']);
        self::assertSame(6, $result['roles'][0]['expected_revision']);
        self::assertSame(7, $result['roles'][0]['observed_revision']);
        self::assertFalse($result['roles'][1]['relation_equal']);
        self::assertNull($result['roles'][1]['expected_revision']);
        self::assertSame('archived', $result['roles'][2]['status']);
        self::assertNull($result['roles'][3]['status']);
        self::assertNull($result['roles'][3]['observed_revision']);
        foreach ([
            ['code' => 'role_revision_mismatch', 'role_id' => '501'],
            ['code' => 'role_assignment_missing', 'role_id' => '502'],
            ['code' => 'role_inactive', 'role_id' => '503'],
            ['code' => 'role_missing', 'role_id' => '504'],
            ['code' => 'role_assignment_outside_scope', 'role_id' => '401'],
        ] as $issue) {
            self::assertContains($issue, $result['unresolved']);
        }

        $empty = $this->candidate();
        $empty['role_assignments'][0]['node_keys'] = [];
        $cleared = $this->audit($empty);
        self::assertFalse($cleared['equivalent']);
        self::assertSame([], $cleared['unresolved']);
        self::assertSame(
            [str_repeat('a', 64), str_repeat('b', 64), str_repeat('c', 64)],
            $cleared['roles'][0]['relation']['removed'],
        );
    }

    public function testWholeCatalogCoverageAndOwnerDynamicGrantsAreReportedSeparately(): void
    {
        $candidate = $this->candidate();
        $candidate['role_ids'] = [502, 501, 401, 301, 202, 201, 101];
        $ownerNode = 'platform.fixture.owner-operation';
        $candidate['nodes'][] = ['key' => $ownerNode, 'parent_key' => null, 'type' => 'operation', 'api_ids' => [str_repeat('1', 64)]];
        array_push(
            $candidate['role_assignments'],
            ['role_id' => '502', 'expected_revision' => 3, 'node_keys' => []],
            ['role_id' => '401', 'expected_revision' => 1, 'node_keys' => []],
            ['role_id' => '301', 'expected_revision' => 1, 'node_keys' => []],
            ['role_id' => '202', 'expected_revision' => 1, 'node_keys' => ['platform.fixture.page']],
            ['role_id' => '201', 'expected_revision' => 1, 'node_keys' => $candidate['role_assignments'][0]['node_keys']],
            ['role_id' => '101', 'expected_revision' => 1, 'node_keys' => [$ownerNode]],
        );
        $report = $this->audit($candidate);
        self::assertTrue($report['scope_covers_catalog']);
        self::assertTrue($report['equivalent']);
        $byRole = array_column($report['roles'], null, 'role_id');
        self::assertTrue($byRole['301']['owner_dynamic']);
        self::assertFalse($byRole['401']['owner_dynamic']);
        self::assertSame(['added' => [], 'removed' => []], $byRole['301']['relation']);

        $candidate['role_assignments'][3]['node_keys'] = [$ownerNode];
        $report = $this->audit($candidate);
        $byRole = array_column($report['roles'], null, 'role_id');
        self::assertTrue($byRole['301']['capability_equal']);
        self::assertFalse($byRole['301']['relation_equal']);
        self::assertSame([str_repeat('1', 64)], $byRole['301']['relation']['added']);
        self::assertFalse($report['equivalent']);
    }

    public function testBadLegacyGrantsAndPrerequisitesCannotBeHiddenByAnEqualEffectiveSet(): void
    {
        $this->database->exec('INSERT INTO pa_platform_role_permission VALUES (501,21),(501,999)');
        $candidate = $this->candidate();
        $bad = $this->audit($candidate);
        self::assertFalse($bad['equivalent']);
        self::assertFalse($bad['roles'][0]['relation_equal']);
        self::assertContains(['code' => 'legacy_role_grant_not_active_platform', 'role_id' => '501'], $bad['unresolved']);

        $candidate['role_ids'] = [201];
        $candidate['role_assignments'][0]['role_id'] = 201;
        $candidate['role_assignments'][0]['expected_revision'] = 1;
        $candidate['role_assignments'][0]['node_keys'] = ['platform.fixture.page', 'platform.fixture.operation'];
        $missingPrerequisite = $this->audit($candidate);
        self::assertFalse($missingPrerequisite['equivalent']);
        self::assertTrue($missingPrerequisite['roles'][0]['capability_equal']);
        self::assertFalse($missingPrerequisite['roles'][0]['relation_equal']);
        self::assertSame([str_repeat('b', 64)], $missingPrerequisite['roles'][0]['relation']['removed']);
        self::assertSame([], $missingPrerequisite['roles'][0]['prerequisite_permission_keys']);
    }

    public function testInvalidLegacyApiBindingAndUnmaterializedDefaultAreAttributedToTheirRole(): void
    {
        $apiId = str_repeat('f', 64);
        $this->database->prepare('INSERT INTO pa_permission_api (permission_id,api_id) VALUES (11,?)')->execute([$apiId]);
        $this->database->prepare('DELETE FROM pa_permission_api WHERE permission_id=11 AND api_id=?')->execute([str_repeat('c', 64)]);
        $candidate = $this->candidate();
        $candidate['role_ids'][] = 502;
        $candidate['role_assignments'][] = ['role_id' => 502, 'expected_revision' => 3, 'node_keys' => []];
        $candidate['role_assignments'][0]['node_keys'] = ['platform.fixture.page', 'platform.fixture.write'];

        $report = $this->audit($candidate);
        self::assertFalse($report['equivalent']);
        self::assertFalse($report['roles'][0]['relation_equal']);
        self::assertFalse($report['roles'][0]['capability_equal']);
        self::assertSame(['added' => [], 'removed' => []], $report['roles'][0]['relation']);
        self::assertTrue($report['roles'][1]['relation_equal']);
        self::assertContains([
            'code' => 'legacy_api_not_active_platform',
            'permission_key' => 'platform.fixture.read',
            'api_id' => $apiId,
        ], $report['unresolved']);
        self::assertContains([
            'code' => 'legacy_default_not_materialized',
            'permission_key' => 'platform.fixture.read',
            'api_id' => str_repeat('c', 64),
        ], $report['unresolved']);
    }

    public function testScopeAndRevisionValidationAndActualReadPermissions(): void
    {
        $candidate = $this->candidate();
        $same = $candidate['role_assignments'][0];
        $same['node_keys'] = array_reverse($same['node_keys']);
        $candidate['role_assignments'][] = $same;
        self::assertTrue($this->audit($candidate)['equivalent']);
        $candidate['role_assignments'][1]['expected_revision'] = 8;
        $this->assertRejected('ROLE_AUTHORIZATION_PREVIEW_INVALID', fn() => $this->audit($candidate));
        $candidate['role_ids'] = [];
        $this->assertRejected('ROLE_AUTHORIZATION_PREVIEW_INVALID', fn() => $this->audit($candidate));

        foreach ([1, 2, 3] as $permissionId) {
            $this->database->exec("DELETE FROM pa_platform_role_permission WHERE platform_role_id=101 AND permission_id={$permissionId}");
            $this->database->exec('UPDATE pa_platform_role SET revision=revision+1 WHERE id=101');
            try {
                $this->audit([]);
                self::fail('The audit accepted an actor with a required platform read grant removed.');
            } catch (AuthorizationException) {
                self::assertTrue(true);
            }
            $this->database->exec("INSERT INTO pa_platform_role_permission VALUES (101,{$permissionId})");
            $this->database->exec('UPDATE pa_platform_role SET revision=revision+1 WHERE id=101');
        }
    }

    public function testCatalogSqlReadCountIsConstantForOneAndEighteenRoles(): void
    {
        $manifest = ManifestDocument::fromObject('', (object) ['key' => 'fixture.optional', 'version' => '1.0.0', 'schema_version' => 1]);
        $registry = new CompiledModuleRegistry([$manifest], [], [], [], 'catalog-audit-fixture');
        PlatformRoleAuthorizationCatalogAuditFixture::installOptionalModule($this->database, $manifest->digest);
        $this->modules = new ModuleAvailabilityService($registry, new TenantEntitlementService($registry));
        $one = $this->candidate();
        $eighteen = $one;
        for ($id = 600; $id < 617; ++$id) {
            $this->database->exec("INSERT INTO pa_platform_role (id,\"key\",status) VALUES ({$id},'platform.fixture.role-{$id}','active')");
            $this->database->exec("INSERT INTO pa_platform_role_permission VALUES ({$id},11),({$id},12)");
            $eighteen['role_ids'][] = $id;
            $eighteen['role_assignments'][] = ['role_id' => $id, 'expected_revision' => 1, 'node_keys' => $one['role_assignments'][0]['node_keys']];
        }
        self::assertTrue($this->audit($one)['equivalent']);
        $statements = [];
        Db::listen(static function (string $statement) use (&$statements): void {
            $statements[] = $statement;
        });
        foreach (['active', 'failed'] as $status) {
            $this->database->prepare('UPDATE pa_module_installation SET status=?')->execute([$status]);
            $statements = [];
            self::assertTrue($this->audit($one)['equivalent']);
            $baseline = count($statements);
            self::assertGreaterThan(0, $baseline);
            $statements = [];
            $report = $this->audit($eighteen);
            self::assertTrue($report['equivalent']);
            self::assertCount(18, $report['roles']);
            self::assertCount($baseline, $statements);
            self::assertCount(1, array_filter($statements, static fn(string $sql): bool => str_contains($sql, 'pa_module_installation')));
            self::assertSame([], array_values(array_filter(
                $statements,
                static fn(string $sql): bool => preg_match('/^\\s*(INSERT|UPDATE|DELETE|REPLACE)\\b/i', $sql) === 1,
            )));
        }
    }

    public function testNativeAssignmentAndExplicitRevocationUpdateExistingAuthorizationAndRejectRepeatedRevision(): void
    {
        PlatformRoleAuthorizationCatalogAuditFixture::enablePlatformWrites($this->database);
        $service = new PlatformAccessAdminService(new AuditService(), new PasswordHasher(), new PasswordPolicy());
        $candidate = $this->candidate();
        $target = $this->platformContext(44);
        $owner = $this->platformContext(33);
        self::assertSame(0, $this->audit($candidate)['roles'][0]['member_count']);
        self::assertFalse($this->authorization->allows($target, 'platform.fixture.read'));
        $operator = $service->replaceOperatorRoles($owner, 44, [501], 1, 'Assign the catalog role');
        self::assertSame(2, (int) $operator['security_revision']);
        self::assertSame(1, $this->audit($candidate)['roles'][0]['member_count']);
        self::assertTrue($this->authorization->allows($target, 'platform.fixture.read'));
        self::assertTrue($this->authorization->allows($target, 'platform.fixture.prerequisite'));

        $revoked = $service->replaceRolePermissions($owner, 501, [], 7, 'Clear all stored role grants');
        self::assertSame(8, (int) $revoked['revision']);
        self::assertSame([], $revoked['permission_keys']);
        self::assertFalse($this->authorization->allows($target, 'platform.fixture.read'));
        self::assertFalse($this->authorization->allows($target, 'platform.fixture.prerequisite'));
        self::assertSame(3, (int) $this->database->query('SELECT security_revision FROM pa_platform_operator WHERE id=44')->fetchColumn());
        self::assertFalse($this->audit($candidate)['equivalent']);
        $candidate['role_assignments'][0] = ['role_id' => 501, 'expected_revision' => 8, 'node_keys' => []];
        self::assertTrue($this->audit($candidate)['equivalent']);
        self::assertSame(
            ['platform-operator.roles-replaced', 'platform-role.permissions-replaced'],
            $this->database->query('SELECT event_type FROM pa_platform_audit_event ORDER BY id')->fetchAll(PDO::FETCH_COLUMN),
        );
        $before = $this->snapshot();
        $this->assertRejected('REVISION_MISMATCH', fn() => $service->replaceRolePermissions($owner, 501, [], 7, 'Repeat the old revision'));
        self::assertSame($before, $this->snapshot());

        $next = $service->replaceRolePermissions($owner, 501, [], 8, 'Repeat with the refreshed revision');
        self::assertSame(9, (int) $next['revision']);
        self::assertSame([], $next['permission_keys']);
        self::assertSame(3, (int) $this->database->query('SELECT COUNT(*) FROM pa_platform_audit_event')->fetchColumn());
    }

    public function testNativeOwnerDynamicCapabilitySurvivesAnEmptyGrantSetAndLastAdminRollback(): void
    {
        PlatformRoleAuthorizationCatalogAuditFixture::enablePlatformWrites($this->database);
        $service = new PlatformAccessAdminService(new AuditService(), new PasswordHasher(), new PasswordPolicy());
        $owner = $this->platformContext(33);
        $role = $service->replaceRolePermissions($owner, 301, [], 1, 'Keep bootstrap ownership and clear stored grants');
        self::assertSame(2, (int) $role['revision']);
        self::assertSame([], $role['permission_keys']);
        self::assertTrue($this->authorization->allows($owner, 'platform.role.permission.assign'));
        $before = $this->snapshot();
        $this->assertRejected('PLATFORM_CONTROL_ADMIN_REQUIRED', fn() => $service->replaceOperatorRoles($owner, 33, [], 2, 'Remove last administrator'));
        self::assertSame($before, $this->snapshot());
        self::assertTrue($this->authorization->allows($owner, 'platform.role.permission.assign'));
    }

    public function testNativeControlPermissionsProtectTheLastNonOwnerAdministrator(): void
    {
        PlatformRoleAuthorizationCatalogAuditFixture::enablePlatformWrites($this->database);
        $controls = [
            'platform.operator.create', 'platform.operator.update', 'platform.operator.lifecycle',
            'platform.operator.role.assign', 'platform.role.create', 'platform.role.update',
            'platform.role.archive', 'platform.role.permission.assign',
        ];
        $insert = $this->database->prepare("INSERT INTO pa_permission (\"key\",status) VALUES (?,'active')");
        foreach ($controls as $key) {
            $insert->execute([$key]);
        }

        $service = new PlatformAccessAdminService(new AuditService(), new PasswordHasher(), new PasswordPolicy());
        $owner = $this->platformContext(33);
        $administrator = $this->platformContext(44);
        self::assertFalse($this->authorization->allows($administrator, 'platform.role.permission.assign'));
        $role = $service->replaceRolePermissions($owner, 401, $controls, 1, 'Grant the full set of control permissions');
        self::assertSame(2, (int) $role['revision']);
        self::assertEqualsCanonicalizing($controls, $role['permission_keys']);
        $operator = $service->replaceOperatorRoles($owner, 33, [], 1, 'Transfer the last administrator responsibility');
        self::assertSame([], $operator['role_keys']);
        self::assertSame(2, (int) $operator['security_revision']);
        self::assertFalse($this->authorization->allows($owner, 'platform.role.permission.assign'));
        foreach ($controls as $key) {
            self::assertTrue($this->authorization->allows($administrator, $key), $key);
        }

        $before = $this->snapshot();
        $remaining = array_values(array_diff($controls, ['platform.operator.create']));
        $this->assertRejected(
            'PLATFORM_CONTROL_ADMIN_REQUIRED',
            fn() => $service->replaceRolePermissions($administrator, 401, $remaining, 2, 'Try to remove a required control permission'),
        );
        self::assertSame($before, $this->snapshot());
        self::assertSame(2, (int) $this->database->query('SELECT revision FROM pa_platform_role WHERE id=401')->fetchColumn());
        self::assertSame(2, (int) $this->database->query('SELECT security_revision FROM pa_platform_operator WHERE id=44')->fetchColumn());
        foreach ($controls as $key) {
            self::assertTrue($this->authorization->allows($administrator, $key), $key);
        }
        self::assertSame(
            ['platform-role.permissions-replaced', 'platform-operator.roles-replaced'],
            $this->database->query('SELECT event_type FROM pa_platform_audit_event ORDER BY id')->fetchAll(PDO::FETCH_COLUMN),
        );
    }

    public function testTenantSameIdRoleAndPermissionsRemainIsolatedByNativeServices(): void
    {
        PlatformRoleAuthorizationCatalogAuditFixture::enablePlatformWrites($this->database);
        PlatformRoleAuthorizationCatalogAuditFixture::enableTenantRoles($this->database);
        $registry = new CompiledModuleRegistry([], [], [], [], 'catalog-audit-fixture');
        $tenantRoles = new RoleAdminService(new AuditService(), new TenantEntitlementService($registry));
        $platformRoles = new PlatformAccessAdminService(new AuditService(), new PasswordHasher(), new PasswordPolicy());

        self::assertSame(['tenant.fixture.foreign'], $tenantRoles->get(71, 501)['permission_keys']);
        self::assertSame('core.tenant-owner', $tenantRoles->get(72, 701)['key']);
        self::assertSame('platform.fixture.future', $this->audit($this->candidate())['roles'][0]['role_key']);
        $before = $this->snapshot();
        $this->assertRejected('RESOURCE_NOT_FOUND', fn() => $tenantRoles->replacePermissions($this->tenantContext(73), 501, [], 7));
        $this->assertRejected('TENANT_OWNER_PERMISSIONS_FIXED', fn() => $tenantRoles->replacePermissions($this->tenantContext(72), 701, [], 7));
        $this->assertRejected('PERMISSION_NOT_ASSIGNABLE', fn() => $tenantRoles->replacePermissions($this->tenantContext(71), 501, ['platform.fixture.read'], 7));
        $this->assertRejected('PERMISSION_NOT_ASSIGNABLE', fn() => $platformRoles->replaceRolePermissions($this->platformContext(33), 501, ['tenant.fixture.foreign'], 7, 'Attempt a tenant grant'));
        self::assertSame($before, $this->snapshot());
    }

    private function candidate(): array
    {
        $base = PlatformRoleAuthorizationPreviewFixture::exactCandidate();

        return [
            'role_ids' => ['501'],
            'nodes' => $base['nodes'],
            'role_assignments' => [['role_id' => '501', 'expected_revision' => '7', 'node_keys' => $base['role_assignments'][0]['node_keys']]],
        ];
    }

    private function audit(array $candidate): array
    {
        return $this->preview->auditCatalog($this->platformContext(11), $candidate, $this->modules);
    }

    private function platformContext(int $operatorId): PlatformContext
    {
        return PlatformContext::fromValidatedSession(
            new ValidatedPlatformSession(1, 'catalog-fixture', intdiv($operatorId, 11) * 111, $operatorId, 'platform-web', new DateTimeImmutable('2031-01-01T00:00:00Z')),
            'platform-role-catalog-fixture',
        );
    }

    private function tenantContext(int $tenantId): TenantContext
    {
        return TenantContext::fromValidatedSession(
            new ValidatedTenantSession(1, 'tenant-fixture', $tenantId, 711, 1, 'admin-web', new DateTimeImmutable('2031-01-01T00:00:00Z'), 1, 1),
            'tenant-role-catalog-fixture',
        );
    }

    private function snapshot(): array
    {
        $snapshot = [];
        $tables = $this->database->query("SELECT name FROM sqlite_master WHERE type='table' AND name LIKE 'pa_%' ORDER BY name")
            ->fetchAll(PDO::FETCH_COLUMN);
        foreach ($tables as $table) {
            $snapshot[$table] = $this->database->query("SELECT * FROM {$table} ORDER BY rowid")->fetchAll(PDO::FETCH_ASSOC);
        }

        return $snapshot;
    }

    private function assertRejected(string $errorCode, callable $action): void
    {
        try {
            $action();
            self::fail('The operation accepted an invalid migration or role change.');
        } catch (AdminAccessException $exception) {
            self::assertSame($errorCode, $exception->errorCode);
        }
    }
}
