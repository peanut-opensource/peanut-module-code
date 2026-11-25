<?php

declare(strict_types=1);

use PeanutAdmin\Kernel\Auth\ValidatedPlatformSession;
use PeanutAdmin\Kernel\Authorization\RevisionPermissionCache;
use PeanutAdmin\Kernel\Context\PlatformContext;
use PeanutAdmin\Kernel\Identity\PasswordHasher;
use PeanutAdmin\Kernel\Identity\PasswordPolicy;
use PeanutAdmin\Kernel\Module\ModuleAvailability;
use PeanutAdmin\Kernel\Platform\Authorization\PlatformAuthorizationEvaluator;
use PeanutAdmin\Modules\Identity\Audit\AuditService;
use PeanutAdmin\Modules\Identity\Authorization\ApiPermissionCatalog;
use PeanutAdmin\Modules\Identity\Contract\PlatformRoleAuthorizationPreview;
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

/**
 * Proof-of-contract only. fixture_* tables NEVER belong to a deployable migration or
 * production authorization source. The original Identity writer remains authoritative.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class PlatformRoleDirectMigrationSimulationTest extends TestCase
{
    private PDO $pdo;
    private PlatformRoleAuthorizationPreview $preview;
    private PlatformAuthorizationEvaluator $authorization;
    private ModuleAvailability $modules;
    private PlatformAccessAdminService $nativeWriter;
    private ?string $temporaryDirectory = null;

    protected function setUp(): void
    {
        $root = dirname(__DIR__, 3);
        self::assertSame(
            $root . '/server/app/modules/peanut/identity/src/Contract/PlatformRoleAuthorizationPreview.php',
            (new ReflectionClass(PlatformRoleAuthorizationPreview::class))->getFileName(),
        );
        require_once $root . '/server/vendor/topthink/framework/src/helper.php';
        $this->temporaryDirectory = $root . '/.local/tmp/platform-role-direct-plan-' . bin2hex(random_bytes(6));
        if (!mkdir($this->temporaryDirectory, 0700, true)) {
            throw new RuntimeException('Could not create the direct migration simulation test directory.');
        }
        $app = new App($this->temporaryDirectory);
        $app->config->set([
            'default' => 'file',
            'stores' => ['file' => ['type' => 'File', 'path' => $this->temporaryDirectory . '/cache/']],
        ], 'cache');
        $this->pdo = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->pdo->exec('PRAGMA foreign_keys=ON');
        ThinkPhpTestConnection::fromPdo($this->pdo);
        PlatformRoleAuthorizationCatalogAuditFixture::install($this->pdo);
        PlatformRoleAuthorizationCatalogAuditFixture::enablePlatformWrites($this->pdo);

        // The concrete persistence contract is intentionally fixture-only: there is no
        // current Platform role->node/API storage table or server-side grant consumer.
        $this->pdo->exec(<<<'SQL'
            CREATE TABLE fixture_platform_role_choice (
                role_id INTEGER PRIMARY KEY, expected_revision INTEGER NOT NULL,
                explicit_empty INTEGER NOT NULL CHECK (explicit_empty IN (0,1)),
                source_sha256 TEXT NOT NULL
            );
            CREATE TABLE fixture_platform_role_node (
                role_id INTEGER NOT NULL, node_key TEXT NOT NULL,
                PRIMARY KEY (role_id,node_key),
                FOREIGN KEY (role_id) REFERENCES fixture_platform_role_choice(role_id)
            );
            CREATE TABLE fixture_platform_role_node_api (
                role_id INTEGER NOT NULL, node_key TEXT NOT NULL, api_id TEXT NOT NULL,
                PRIMARY KEY (role_id,node_key,api_id),
                FOREIGN KEY (role_id,node_key) REFERENCES fixture_platform_role_node(role_id,node_key)
            );
            SQL);

        $repository = new ThinkPhpPlatformAuthorizationRepository();
        $this->authorization = new PlatformAuthorizationEvaluator($repository, new RevisionPermissionCache());
        $this->preview = new PlatformRoleAuthorizationPreview(
            $this->authorization, $repository, new ApiPermissionCatalog(), new DemoAccountPolicy(false, []),
        );
        $this->modules = $this->createStub(ModuleAvailability::class);
        $this->nativeWriter = new PlatformAccessAdminService(new AuditService(), new PasswordHasher(), new PasswordPolicy());
    }

    protected function tearDown(): void
    {
        if ($this->temporaryDirectory !== null && is_dir($this->temporaryDirectory)) {
            $children = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($this->temporaryDirectory, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($children as $child) {
                $child->isDir() && !$child->isLink() ? rmdir($child->getPathname()) : unlink($child->getPathname());
            }
            rmdir($this->temporaryDirectory);
        }
        parent::tearDown();
    }

    public function testEntireDirectoryStagesExactRelationsIncludingExplicitEmptyAndMemberlessRoles(): void
    {
        $candidate = $this->completeCandidate();
        $beforeLegacy = $this->originalTables();
        $hash = $this->catalogDigest($candidate['nodes']);
        $report = $this->stage($candidate, $hash);

        self::assertSame(7, $report['catalog_total']);
        self::assertTrue($report['scope_covers_catalog']);
        self::assertTrue($report['equivalent']);
        self::assertSame(0, $report['roles'][6]['member_count']); // role 502
        self::assertSame(0, $report['roles'][5]['member_count']); // role 501
        self::assertSame([301, 401, 502], $this->pdo->query(
            'SELECT role_id FROM fixture_platform_role_choice WHERE explicit_empty=1 ORDER BY role_id',
        )->fetchAll(PDO::FETCH_COLUMN));
        self::assertSame(7, (int) $this->pdo->query('SELECT COUNT(*) FROM fixture_platform_role_choice')->fetchColumn());
        self::assertSame(0, (int) $this->pdo->query(
            'SELECT COUNT(*) FROM fixture_platform_role_node WHERE role_id=502',
        )->fetchColumn());
        self::assertGreaterThan(0, (int) $this->pdo->query(
            'SELECT COUNT(*) FROM fixture_platform_role_node_api WHERE role_id=501',
        )->fetchColumn());
        self::assertSame($hash, $this->pdo->query(
            'SELECT source_sha256 FROM fixture_platform_role_choice WHERE role_id=502',
        )->fetchColumn());
        self::assertSame($beforeLegacy, $this->originalTables(), 'Staging must not change legacy grants/revisions/audit');
        self::assertTrue($this->authorization->allows($this->platformContext(33), 'platform.role.permission.assign'));
    }

    public function testMissingAssignmentAndExplicitClearOfGrantedRoleAbortBeforeAnyStaging(): void
    {
        $candidate = $this->completeCandidate();
        $hash = $this->catalogDigest($candidate['nodes']);
        $before = $this->allTables();
        $candidate['role_assignments'] = array_values(array_filter(
            $candidate['role_assignments'], static fn(array $row): bool => (int) $row['role_id'] !== 502,
        ));
        $this->assertPlanRejected('G18_EQUIVALENCE_REQUIRED', fn() => $this->stage($candidate, $hash));
        self::assertSame($before, $this->allTables());

        $candidate = $this->completeCandidate();
        foreach ($candidate['role_assignments'] as &$choice) {
            if ((int) $choice['role_id'] === 501) {
                $choice['node_keys'] = [];
            }
        }
        unset($choice);
        $this->assertPlanRejected('G18_EQUIVALENCE_REQUIRED', fn() => $this->stage($candidate, $hash));
        self::assertSame($before, $this->allTables());
    }

    public function testApiAndPermissionBindingDriftAbortWithoutAChangedRoleRevision(): void
    {
        foreach (['endpoint', 'binding'] as $variation) {
            $candidate = $this->completeCandidate();
            $hash = $this->catalogDigest($candidate['nodes']);
            $beforeRevision = $this->roleRevision(501);
            if ($variation === 'endpoint') {
                $this->pdo->prepare("UPDATE pa_system_api SET status='retired' WHERE id=?")
                    ->execute([str_repeat('c', 64)]);
            } else {
                $this->pdo->prepare('DELETE FROM pa_permission_api WHERE permission_id=11 AND api_id=?')
                    ->execute([str_repeat('c', 64)]);
            }
            $beforeAttempt = $this->allTables();
            $this->assertPlanRejected('G18_CATALOG_DRIFT', fn() => $this->stage($candidate, $hash));
            self::assertSame($beforeRevision, $this->roleRevision(501));
            self::assertSame($beforeAttempt, $this->allTables());
            $this->assertPlanRejected('G18_EQUIVALENCE_REQUIRED', fn() =>
                $this->stage($candidate, $this->catalogDigest($candidate['nodes'])));
            self::assertSame($beforeAttempt, $this->allTables());
            if ($variation === 'endpoint') {
                $this->pdo->prepare("UPDATE pa_system_api SET status='active' WHERE id=?")
                    ->execute([str_repeat('c', 64)]);
            } else {
                $this->pdo->prepare('INSERT INTO pa_permission_api (permission_id,api_id) VALUES (11,?)')
                    ->execute([str_repeat('c', 64)]);
            }
        }
    }

    public function testStaleRevisionRepeatedRequestAndBatchConflictAllRollback(): void
    {
        $candidate = $this->completeCandidate();
        $hash = $this->catalogDigest($candidate['nodes']);
        $candidate['role_assignments'][0]['expected_revision'] = '2';
        $before = $this->allTables();
        $this->assertPlanRejected('G18_EQUIVALENCE_REQUIRED', fn() => $this->stage($candidate, $hash));
        self::assertSame($before, $this->allTables());

        $candidate = $this->completeCandidate();
        $this->stage($candidate, $hash);
        $staged = $this->allTables();
        $this->assertPlanRejected('G18_PLAN_ALREADY_STAGED', fn() => $this->stage($candidate, $hash));
        self::assertSame($staged, $this->allTables());

        $this->pdo->exec('DELETE FROM fixture_platform_role_node_api');
        $this->pdo->exec('DELETE FROM fixture_platform_role_node');
        $this->pdo->exec('DELETE FROM fixture_platform_role_choice');
        $before = $this->allTables();
        $this->assertPlanRejected('G18_SYNTHETIC_POST_WRITE_ABORT', fn() =>
            $this->stage($candidate, $hash, static function (): void {
                throw new DomainException('G18_SYNTHETIC_POST_WRITE_ABORT');
            }));
        self::assertSame($before, $this->allTables(), 'A late conflict rolls back every staged role');
    }

    public function testNativeRevocationAndLastAdministratorGuardsStayAuthoritative(): void
    {
        $candidate = $this->completeCandidate();
        $hash = $this->catalogDigest($candidate['nodes']);
        $this->stage($candidate, $hash);
        $snapshot = $this->allTables();
        $this->assertNativeRejected('PLATFORM_CONTROL_ADMIN_REQUIRED', fn() =>
            $this->nativeWriter->replaceOperatorRoles(
                $this->platformContext(33), 33, [], 1, 'Never remove the only Bootstrap Owner',
            ));
        self::assertSame($snapshot, $this->allTables());

        $assigned = $this->nativeWriter->replaceOperatorRoles(
            $this->platformContext(33), 44, [501], 1, 'Assign the memberless role in this fixture',
        );
        self::assertSame(2, (int) $assigned['security_revision']);
        self::assertTrue($this->authorization->allows($this->platformContext(44), 'platform.fixture.read'));
        $revoked = $this->nativeWriter->replaceRolePermissions(
            $this->platformContext(33), 501, [], 7, 'Synthetic revocation through the existing writer',
        );
        self::assertSame(8, (int) $revoked['revision']);
        self::assertSame([], $revoked['permission_keys']);
        self::assertFalse($this->authorization->allows($this->platformContext(44), 'platform.fixture.read'));
        self::assertTrue($this->authorization->allows($this->platformContext(33), 'platform.role.permission.assign'));
        $before = $this->allTables();
        $this->assertPlanRejected('G18_EQUIVALENCE_REQUIRED', fn() =>
            $this->stage($candidate, $this->catalogDigest($candidate['nodes'])));
        self::assertSame($before, $this->allTables());
        $this->assertNativeRejected('REVISION_MISMATCH', fn() =>
            $this->nativeWriter->replaceRolePermissions(
                $this->platformContext(33), 501, [], 7, 'Repeat a stale role revision',
            ));
        self::assertSame($before, $this->allTables());
    }


    public function testOriginalWriterRevisionAndAuditRollbackInsideFixtureStagingTransaction(): void
    {
        $candidate = $this->completeCandidate();
        $hash = $this->catalogDigest($candidate['nodes']);
        $before = $this->allTables();
        $this->assertPlanRejected('G18_SYNTHETIC_POST_WRITE_ABORT', fn() =>
            $this->stage($candidate, $hash, function (): void {
                $written = $this->nativeWriter->replaceRolePermissions(
                    $this->platformContext(33), 501,
                    ['platform.fixture.read', 'platform.fixture.prerequisite'], 7,
                    'Existing Identity writer only in an aborting SQLite fixture transaction',
                );
                self::assertSame(8, (int) $written['revision']);
                self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM pa_platform_audit_event')->fetchColumn());
                throw new DomainException('G18_SYNTHETIC_POST_WRITE_ABORT');
            }));
        self::assertSame($before, $this->allTables(), 'Legacy grants, role revision, audit and proposed relations roll back together');
    }

    public function testLastAdministratorNativeRejectionRollsBackWholeStagedBatch(): void
    {
        $candidate = $this->completeCandidate();
        $hash = $this->catalogDigest($candidate['nodes']);
        $before = $this->allTables();
        $this->assertNativeRejected('PLATFORM_CONTROL_ADMIN_REQUIRED', fn() =>
            $this->stage($candidate, $hash, fn() =>
                $this->nativeWriter->replaceOperatorRoles(
                    $this->platformContext(33), 33, [], 1,
                    'The native last administrator guard cannot be bypassed by a planned node migration',
                ),
            ));
        self::assertSame($before, $this->allTables(), 'Native last administrator guard must abort fixture relation staging');
    }

    public function testPlatformAndTenantRoleIdsAndApiAudienceCannotBeMixed(): void
    {
        PlatformRoleAuthorizationCatalogAuditFixture::enableTenantRoles($this->pdo);
        $candidate = $this->completeCandidate();
        $hash = $this->catalogDigest($candidate['nodes']);
        $before = $this->allTables();
        $candidate['nodes'][] = [
            'key' => 'platform.fixture.tenant-api', 'parent_key' => null,
            'type' => 'operation', 'api_ids' => [str_repeat('e', 64)],
        ];
        $this->assertPlanRejected('G18_CATALOG_DRIFT', fn() => $this->stage($candidate, $hash));
        $this->assertPlanRejected('G18_EQUIVALENCE_REQUIRED', fn() =>
            $this->stage($candidate, $this->catalogDigest($candidate['nodes'])));
        self::assertSame($before, $this->allTables());
        $this->stage($this->completeCandidate(), $hash);
        self::assertSame(['tenant.fixture.foreign'], $this->pdo->query(
            "SELECT permission.key FROM pa_role_permission binding JOIN pa_permission permission ON permission.id=binding.permission_id WHERE binding.tenant_id=71 AND binding.role_id=501",
        )->fetchAll(PDO::FETCH_COLUMN));
    }


    public function testUnregisteredNodeAliasCanMatchApisButDoesNotProveFormalSourceIdentity(): void
    {
        $candidate = $this->completeCandidate();
        // auditCatalog is an equivalence calculator, not a source-of-truth registry:
        // arbitrary candidate keys with the same API IDs remain semantically equal.
        $oldKey = 'platform.fixture.operation';
        $alias = 'platform.fixture.unregistered-alias';
        foreach ($candidate['nodes'] as &$node) {
            if ($node['key'] === $oldKey) {
                $node['key'] = $alias;
            }
        }
        unset($node);
        foreach ($candidate['role_assignments'] as &$assignment) {
            $assignment['node_keys'] = array_map(
                static fn(string $key): string => $key === $oldKey ? $alias : $key,
                $assignment['node_keys'],
            );
        }
        unset($assignment);

        $before = $this->originalTables();
        $report = $this->stage($candidate, $this->catalogDigest($candidate['nodes']));
        self::assertTrue($report['equivalent'], 'Equal API IDs cannot establish authoritative node identity');
        self::assertGreaterThan(0, (int) $this->pdo->query(
            "SELECT COUNT(*) FROM fixture_platform_role_node WHERE node_key='platform.fixture.unregistered-alias'",
        )->fetchColumn());
        self::assertSame($before, $this->originalTables());
        // A real writer MUST resolve node keys from registered Identity menus
        // and canonical operation definitions; a user-provided digest is insufficient.
    }

    public function testConflictingDuplicateRoleOrNodeDefinitionsCannotStage(): void
    {
        $candidate = $this->completeCandidate();
        $hash = $this->catalogDigest($candidate['nodes']);
        $before = $this->allTables();
        $duplicate = $candidate['role_assignments'][0];
        $duplicate['node_keys'] = [];
        $candidate['role_assignments'][] = $duplicate;
        $this->assertNativeRejected('ROLE_AUTHORIZATION_PREVIEW_INVALID', fn() =>
            $this->stage($candidate, $hash));
        self::assertSame($before, $this->allTables());

        $candidate = $this->completeCandidate();
        $copy = $candidate['nodes'][1];
        $copy['api_ids'] = [];
        $candidate['nodes'][] = $copy;
        $this->assertPlanRejected('G18_CATALOG_DRIFT', fn() => $this->stage($candidate, $hash));
        $this->assertNativeRejected('ROLE_AUTHORIZATION_PREVIEW_INVALID', fn() =>
            $this->stage($candidate, $this->catalogDigest($candidate['nodes'])));
        self::assertSame($before, $this->allTables());
    }

    /** @return array<string,mixed> */
    private function completeCandidate(): array
    {
        $base = PlatformRoleAuthorizationPreviewFixture::exactCandidate();
        $nodes = $base['nodes'];
        $nodes[] = [
            'key' => 'platform.fixture.owner-operation', 'parent_key' => null,
            'type' => 'operation', 'api_ids' => [str_repeat('1', 64)],
        ];
        return [
            'role_ids' => ['101', '201', '202', '301', '401', '501', '502'],
            'nodes' => $nodes,
            'role_assignments' => [
                ['role_id' => '101', 'expected_revision' => 1, 'node_keys' => ['platform.fixture.owner-operation']],
                ['role_id' => '201', 'expected_revision' => 1, 'node_keys' => $base['role_assignments'][0]['node_keys']],
                ['role_id' => '202', 'expected_revision' => 1, 'node_keys' => ['platform.fixture.page']],
                ['role_id' => '301', 'expected_revision' => 1, 'node_keys' => []],
                ['role_id' => '401', 'expected_revision' => 1, 'node_keys' => []],
                ['role_id' => '501', 'expected_revision' => 7, 'node_keys' => $base['role_assignments'][0]['node_keys']],
                ['role_id' => '502', 'expected_revision' => 3, 'node_keys' => []],
            ],
        ];
    }

    /**
     * Test-only transaction plan. No production role-to-node table or runtime write
     * action is introduced. The native Identity audit is the only equivalence check.
     *
     * @return array<string,mixed>
     */
    private function stage(array $candidate, string $expectedDigest, ?Closure $afterWrite = null): array
    {
        return Db::transaction(function () use ($candidate, $expectedDigest, $afterWrite): array {
            if (!hash_equals($expectedDigest, $this->catalogDigest($candidate['nodes']))) {
                throw new DomainException('G18_CATALOG_DRIFT');
            }
            $report = $this->preview->auditCatalog($this->platformContext(11), $candidate, $this->modules);
            if (!$report['scope_covers_catalog'] || !$report['equivalent']) {
                throw new DomainException('G18_EQUIVALENCE_REQUIRED');
            }
            $byId = array_column($candidate['role_assignments'], null, 'role_id');
            $byKey = array_column($candidate['nodes'], null, 'key');
            $roleStatement = $this->pdo->prepare(
                'INSERT INTO fixture_platform_role_choice (role_id,expected_revision,explicit_empty,source_sha256) VALUES (?,?,?,?)',
            );
            $nodeStatement = $this->pdo->prepare(
                'INSERT INTO fixture_platform_role_node (role_id,node_key) VALUES (?,?)',
            );
            $apiStatement = $this->pdo->prepare(
                'INSERT INTO fixture_platform_role_node_api (role_id,node_key,api_id) VALUES (?,?,?)',
            );
            foreach ($candidate['role_ids'] as $roleId) {
                $choice = $byId[$roleId];
                $count = count($choice['node_keys']);
                if ($this->pdo->query('SELECT COUNT(*) FROM fixture_platform_role_choice WHERE role_id=' . (int) $roleId)->fetchColumn()) {
                    throw new DomainException('G18_PLAN_ALREADY_STAGED');
                }
                $roleStatement->execute([(int) $roleId, (int) $choice['expected_revision'], $count === 0 ? 1 : 0, $expectedDigest]);
                foreach ($choice['node_keys'] as $key) {
                    $nodeStatement->execute([(int) $roleId, $key]);
                    foreach ($byKey[$key]['api_ids'] as $apiId) {
                        $apiStatement->execute([(int) $roleId, $key, $apiId]);
                    }
                }
            }
            if ($afterWrite !== null) {
                $afterWrite();
            }
            return $report;
        });
    }

    private function catalogDigest(array $nodes): string
    {
        $source = [];
        foreach ([
            'SELECT id,method,path,audience,access_mode,status,module_key,default_permission_keys,prerequisite_permission_keys FROM pa_system_api ORDER BY id',
            'SELECT id,"key",status,api_configured FROM pa_permission ORDER BY id',
            'SELECT permission_id,api_id FROM pa_permission_api ORDER BY permission_id,api_id',
            'SELECT "key",scope,type,status FROM pa_system_menu ORDER BY "key"',
        ] as $query) {
            $source[] = $this->pdo->query($query)->fetchAll(PDO::FETCH_ASSOC);
        }
        usort($nodes, static fn(array $a, array $b): int => strcmp($a['key'], $b['key']));
        $source[] = $nodes;
        return hash('sha256', json_encode($source, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    private function roleRevision(int $roleId): int
    {
        return (int) $this->pdo->query("SELECT revision FROM pa_platform_role WHERE id={$roleId}")->fetchColumn();
    }

    private function originalTables(): array
    {
        return array_filter($this->allTables(), static fn(string $name): bool => str_starts_with($name, 'pa_'), ARRAY_FILTER_USE_KEY);
    }

    private function allTables(): array
    {
        $tables = $this->pdo->query(
            "SELECT name FROM sqlite_master WHERE type='table' AND (name LIKE 'pa_%' OR name LIKE 'fixture_%') ORDER BY name",
        )->fetchAll(PDO::FETCH_COLUMN);
        $snapshot = [];
        foreach ($tables as $table) {
            $snapshot[$table] = $this->pdo->query("SELECT * FROM \"{$table}\" ORDER BY rowid")->fetchAll(PDO::FETCH_ASSOC);
        }
        return $snapshot;
    }

    private function platformContext(int $operatorId): PlatformContext
    {
        return PlatformContext::fromValidatedSession(
            new ValidatedPlatformSession(1, 'g18-direct-plan-fixture', intdiv($operatorId, 11) * 111,
                $operatorId, 'platform-web', new DateTimeImmutable('2031-01-01T00:00:00Z')),
            'g18-direct-plan-fixture',
        );
    }

    private function assertPlanRejected(string $expectedCode, callable $action): void
    {
        try {
            $action();
            self::fail('The plan accepted an invalid candidate.');
        } catch (DomainException $exception) {
            self::assertSame($expectedCode, $exception->getMessage());
        }
    }

    private function assertNativeRejected(string $expectedCode, callable $action): void
    {
        try {
            $action();
            self::fail('The native service accepted an invalid mutation.');
        } catch (\PeanutAdmin\Kernel\Authorization\Application\AdminAccessException $exception) {
            self::assertSame($expectedCode, $exception->errorCode);
        }
    }
}
