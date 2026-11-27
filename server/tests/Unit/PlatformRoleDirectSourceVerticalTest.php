<?php

declare(strict_types=1);

use PeanutAdmin\Kernel\Auth\ValidatedPlatformSession;
use PeanutAdmin\Kernel\Authorization\Application\AdminAccessException;
use PeanutAdmin\Kernel\Authorization\AuthorizationException;
use PeanutAdmin\Kernel\Authorization\RevisionPermissionCache;
use PeanutAdmin\Kernel\Context\PlatformContext;
use PeanutAdmin\Kernel\Identity\PasswordHasher;
use PeanutAdmin\Kernel\Identity\PasswordPolicy;
use PeanutAdmin\Kernel\Module\ModuleAvailability;
use PeanutAdmin\Kernel\Platform\Authorization\PlatformAuthorizationEvaluator;
use PeanutAdmin\Modules\Identity\Audit\AuditService;
use PeanutAdmin\Modules\Identity\Authorization\ApiPermissionCatalog;
use PeanutAdmin\Modules\Identity\Contract\PlatformRoleAuthorizationPreview;
use PeanutAdmin\Modules\Identity\Contract\PlatformRoleNodeWriteContract;
use PeanutAdmin\Modules\Identity\Platform\Application\PlatformAccessAdminService;
use PeanutAdmin\Modules\Identity\Platform\Authorization\PlatformRoleDirectGrantStore;
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
 * Module source worktree + already-installed test dependencies. No shared DB,
 * service, Code HTTP endpoint, or live Module main working tree is modified.
 * Code's registered source adapter is represented by a trusted closure that
 * returns its official output shape, without accepting client-supplied nodes.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class PlatformRoleDirectSourceVerticalTest extends TestCase
{
    private App $taskApp;
    private PDO $db;
    private PlatformRoleDirectGrantStore $direct;
    private ThinkPhpPlatformAuthorizationRepository $roles;
    private PlatformAccessAdminService $writer;
    private PlatformRoleAuthorizationPreview $preview;
    private ModuleAvailability $modules;
    private string $tempDir;

    protected function setUp(): void
    {
        $root = dirname(__DIR__, 3);
        self::assertSame(
            $root . '/server/app/modules/peanut/identity/src/Platform/Application/PlatformAccessAdminService.php',
            (new ReflectionClass(PlatformAccessAdminService::class))->getFileName(),
        );
        $autoload = getenv('PEANUT_G18_VENDOR_AUTOLOAD') ?: $root . '/server/vendor/autoload.php';
        require_once dirname($autoload) . '/topthink/framework/src/helper.php';
        $this->tempDir = $root . '/.local/tmp/g18-source-vertical-' . bin2hex(random_bytes(6));
        if (!mkdir($this->tempDir, 0700, true)) {
            throw new RuntimeException('Could not create an isolated G18 test runtime.');
        }
        $app = new App($this->tempDir);
        $this->taskApp = $app;
        $app->config->set([
            'default' => 'file',
            'stores' => ['file' => ['type' => 'File', 'path' => $this->tempDir . '/cache/']],
        ], 'cache');
        $this->db = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->db->exec('PRAGMA foreign_keys=ON');
        ThinkPhpTestConnection::fromPdo($this->db);
        PlatformRoleAuthorizationCatalogAuditFixture::install($this->db);
        // A native installed APP always has Identity's Module lifecycle table.
        // An empty table models an optional Module that is not installed.
        $this->db->exec('CREATE TABLE pa_module_installation (module_key TEXT PRIMARY KEY, status TEXT NOT NULL)');
        PlatformRoleAuthorizationCatalogAuditFixture::enablePlatformWrites($this->db);
        $this->direct = new PlatformRoleDirectGrantStore();
        $this->roles = new ThinkPhpPlatformAuthorizationRepository();
        $authorization = new PlatformAuthorizationEvaluator($this->roles, new RevisionPermissionCache());
        $this->preview = new PlatformRoleAuthorizationPreview(
            $authorization, $this->roles, new ApiPermissionCatalog(), new DemoAccountPolicy(false, []),
        );
        $this->modules = $this->createStub(ModuleAvailability::class);
        $this->writer = new PlatformAccessAdminService(new AuditService(), new PasswordHasher(), new PasswordPolicy());
    }

    protected function tearDown(): void
    {
        if (isset($this->tempDir) && is_dir($this->tempDir)) {
            $contents = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($this->tempDir, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($contents as $item) {
                $item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
            }
            rmdir($this->tempDir);
        }
        parent::tearDown();
    }


    public function testG18NativeMigrationTablesBelongToIdentityManifest(): void
    {
        $root = dirname(__DIR__, 3);
        $manifest = json_decode((string) file_get_contents(
            $root . '/server/app/modules/peanut/identity/module.json',
        ), true, 64, JSON_THROW_ON_ERROR);
        $source = (string) file_get_contents(
            $root . '/server/app/modules/peanut/identity/database/migrations/20261125090000_platform_role_direct_grants.php',
        );
        preg_match_all('/CREATE TABLE (pa_platform_[a-z_]+) \(/', $source, $matches);
        self::assertSame([
            'pa_platform_role_authorization_source',
            'pa_platform_role_direct_state',
            'pa_platform_role_node',
            'pa_platform_role_node_api',
        ], $matches[1]);
        foreach ($matches[1] as $table) {
            self::assertContains($table, $manifest['database']['owned_tables'],
                'Identity lifecycle must account for its own G18 migration table: ' . $table);
        }
    }

    public function testUnmigratedMainApplicationKeepsExistingLegacyAuthorization(): void
    {
        // Drop all direct tables BEFORE first authorization read, representing
        // an actual application that has never received the new migration.
        foreach ([
            'pa_platform_role_node_api', 'pa_platform_role_node',
            'pa_platform_role_direct_state', 'pa_platform_role_authorization_source',
        ] as $table) {
            $this->db->exec('DROP TABLE ' . $table);
        }
        self::assertSame('legacy', $this->direct->source());
        $this->assertRejected('PLATFORM_DIRECT_SCHEMA_NOT_READY',
            fn(): mixed => $this->direct->assertReady());
        self::assertContains('platform.fixture.read', $this->roles->permissions(22)->keys());
        self::assertNotEmpty($this->legacyGrants());
    }

    public function testMissingSourceSwitchWithExistingDirectRowsFailsClosed(): void
    {
        $this->db->exec('DROP TABLE pa_platform_role_authorization_source');
        $this->assertRejected('PLATFORM_AUTHORIZATION_SOURCE_INVALID',
            fn(): string => $this->direct->source());
    }

    public function testTenantApiMappingChangeCannotInvalidatePlatformDirectRoleSource(): void
    {
        $candidate = $this->completeCandidate();
        $digest = $this->direct->catalogDigest($candidate['nodes']);
        $tenantApi = str_repeat('e', 64);
        $statement = $this->db->prepare(
            'SELECT COUNT(*) FROM pa_permission_api WHERE permission_id=? AND api_id=?',
        );
        $statement->execute([21, $tenantApi]);
        self::assertSame(1, (int) $statement->fetchColumn());
        $this->db->prepare('DELETE FROM pa_permission_api WHERE permission_id=? AND api_id=?')
            ->execute([21, $tenantApi]);
        self::assertSame($digest, $this->direct->catalogDigest($candidate['nodes']),
            'A tenant menu/API grant change must not invalidate independent Platform direct role source.');
        $platformApi = str_repeat('a', 64);
        $statement->execute([11, $platformApi]);
        self::assertSame(1, (int) $statement->fetchColumn());
        $this->db->prepare('DELETE FROM pa_permission_api WHERE permission_id=? AND api_id=?')
            ->execute([11, $platformApi]);
        self::assertNotSame($digest, $this->direct->catalogDigest($candidate['nodes']),
            'A real Platform API grant change must still invalidate the direct source digest.');
    }

    public function testFormalRoleSaveAndAtomicSingleSwitchFeedTheSameAuthorizationConsumer(): void
    {
        $original = $this->roles->permissions(22)->keys();
        $originalLegacy = $this->legacyGrants();
        $candidate = $this->completeCandidate();
        $digest = $this->direct->catalogDigest($candidate['nodes']);
        $resolver = $this->trustedSource($candidate['nodes']);
        foreach ($candidate['role_assignments'] as $assignment) {
            $result = $this->writer->replaceRoleNodes(
                $this->actor(11), (int) $assignment['role_id'], $assignment['node_keys'],
                (int) $assignment['expected_revision'], $digest, 'Explicit synthetic role migration', $resolver,
            );
            self::assertSame($assignment['node_keys'], $result['node_keys']);
        }
        self::assertSame('legacy', $this->direct->source());
        self::assertSame($originalLegacy, $this->legacyGrants(), 'Staging must not dual-write legacy role grants.');
        self::assertSame($original, $this->roles->permissions(22)->keys(), 'Before cutover every existing operator keeps legacy permissions.');
        self::assertSame(3, (int) $this->db->query(
            'SELECT COUNT(*) FROM pa_platform_role_direct_state WHERE explicit_empty=1',
        )->fetchColumn());
        $this->writer->activateDirectRoleSource(
            $this->actor(11), $this->preview, $this->modules, $digest,
            'Complete equivalent synthetic cutover', $resolver,
        );
        self::assertSame('direct', $this->direct->source());
        self::assertEqualsCanonicalizing($original, $this->roles->permissions(22)->keys(), 'Direct source must project the equivalent role permission set.');
        self::assertContains('platform.fixture.read', $this->roles->permissions(22)->keys());
        self::assertContains('platform.role.read', $this->roles->permissions(33)->keys(), 'Bootstrap Owner is dynamic.');
        self::assertSame($originalLegacy, $this->legacyGrants());
        $this->assertRejected('PLATFORM_ROLE_SOURCE_DIRECT', fn() => $this->writer->replaceRolePermissions(
            $this->actor(11), 201, ['platform.fixture.read'], 2, 'Cannot dual write',
        ));
        $before = $this->roles->permissions(22)->keys();
        $this->writer->replaceRoleNodes(
            $this->actor(11), 201, [], 2, $digest, 'Explicit role revocation', $resolver,
        );
        self::assertNotSame($before, $this->roles->permissions(22)->keys());
        self::assertSame(1, (int) $this->db->query(
            'SELECT explicit_empty FROM pa_platform_role_direct_state WHERE platform_role_id=201',
        )->fetchColumn());
        self::assertSame($originalLegacy, $this->legacyGrants());
        $beforeAudit = (int) $this->db->query('SELECT COUNT(*) FROM pa_platform_audit_event')->fetchColumn();
        $this->assertRejected('PLATFORM_ROLLBACK_PRIVILEGE_EXPANSION', fn() =>
            $this->writer->rollbackDirectRoleSource($this->actor(11), 'Do not restore revoked grants'));
        self::assertSame('direct', $this->direct->source());
        self::assertSame($beforeAudit, (int) $this->db->query('SELECT COUNT(*) FROM pa_platform_audit_event')->fetchColumn());
        // Once the direct role is explicitly reconciled with the old source,
        // rollback remains available without silently restoring revoked rights.
        $this->writer->replaceRoleNodes(
            $this->actor(11), 201,
            ['platform.fixture.page', 'platform.fixture.write', 'platform.fixture.operation'],
            3, $digest, 'Restore direct grants explicitly before source rollback', $resolver,
        );
        $this->writer->rollbackDirectRoleSource($this->actor(11), 'Emergency legacy-source rollback');
        self::assertSame('legacy', $this->direct->source());
        self::assertSame($original, $this->roles->permissions(22)->keys(), 'Rollback restores untouched legacy source, never merges grants.');
        self::assertSame($originalLegacy, $this->legacyGrants());
        self::assertGreaterThan(7, (int) $this->db->query('SELECT COUNT(*) FROM pa_platform_audit_event')->fetchColumn());
    }

    public function testNativeLegacyRoleWriteSurvivesUntilCutoverAndAfterSafeRollback(): void
    {
        $routeFile = dirname(__DIR__, 3) . '/server/route/platform.php';
        $routeSource = (string) file_get_contents($routeFile);
        self::assertStringContainsString(
            "Route::post('roles/permissions/replace', [PlatformAccessController::class, 'replaceRolePermissions'])",
            $routeSource,
        );
        $store = new \app\common\execution\ExecutionContextStore();
        $this->taskApp->instance(\app\common\execution\CurrentExecutionContext::class,
            new \app\common\execution\CurrentExecutionContext($store));
        $call = function (int $revision, array $keys, string $reason) use ($store): array {
            $request = (new \think\Request())->withServer(['REQUEST_METHOD' => 'POST'])->withPost([
                'role_id' => 502,
                'permission_keys' => $keys,
                'expected_revision' => $revision,
                'change_reason' => $reason,
            ]);
            $request->setPathinfo('roles/permissions/replace');
            $request->setRule((new \think\Route($this->taskApp))
                ->rule('roles/permissions/replace', static fn() => null, 'POST'));
            $this->taskApp->instance('request', $request);
            $this->taskApp->instance(\think\Request::class, $request);
            $context = new \app\common\execution\PlatformExecutionContext(
                \app\platform\context\PlatformOperatorContext::fromValidatedPlatformSession($this->actor(11)),
                'g18.test.legacy.http',
            );
            return $store->runHttp($context, function () {
                $controller = new \app\platform\controller\PlatformAccessController(
                    $this->taskApp, $this->writer,
                );
                $response = $controller->replaceRolePermissions();
                self::assertSame(200, $response->getCode());
                $payload = $response->getData();
                self::assertSame(20000, $payload['code']);
                return $payload['data'];
            });
        };
        self::assertSame('legacy', $this->direct->source());
        $legacyBefore = $this->legacyGrants();
        $saved = $call(3, [], 'Keep legacy role management available during migration');
        self::assertSame('4', $saved['revision']);
        self::assertSame([], $saved['permission_keys']);
        self::assertSame('legacy', $this->direct->source());
        self::assertSame($legacyBefore, $this->legacyGrants(),
            'Explicit empty on a memberless legacy role must not invent a grant.');
        self::assertSame('4', (string) $this->db->query(
            'SELECT revision FROM pa_platform_role WHERE id=502'
        )->fetchColumn(), 'Legacy writer must commit the native CAS revision.');
        self::assertGreaterThan(0, (int) $this->db->query(
            'SELECT COUNT(*) FROM pa_platform_audit_event'
        )->fetchColumn(), 'Legacy writer must still audit the save.');
        $this->assertRejected('REVISION_MISMATCH',
            fn() => $call(3, [], 'Outdated legacy revision'));
        $candidate = $this->completeCandidate();
        foreach ($candidate['role_assignments'] as &$assignment) {
            if ($assignment['role_id'] === 502) {
                $assignment['expected_revision'] = 4;
            }
        }
        unset($assignment);
        $digest = $this->direct->catalogDigest($candidate['nodes']);
        $resolver = $this->trustedSource($candidate['nodes']);
        foreach ($candidate['role_assignments'] as $assignment) {
            $this->writer->replaceRoleNodes(
                $this->actor(11), (int) $assignment['role_id'],
                $assignment['node_keys'], (int) $assignment['expected_revision'],
                $digest, 'Complete synthetic direct staging', $resolver,
            );
        }
        self::assertSame('legacy', $this->direct->source());
        $this->writer->activateDirectRoleSource(
            $this->actor(11), $this->preview, $this->modules, $digest,
            'Atomic direct-source switch', $resolver,
        );
        self::assertSame('direct', $this->direct->source());
        $directBefore = $this->db->query(
            'SELECT * FROM pa_platform_role_direct_state ORDER BY platform_role_id'
        )->fetchAll(PDO::FETCH_ASSOC);
        $grantBefore = $this->legacyGrants();
        $this->assertRejected('PLATFORM_ROLE_SOURCE_DIRECT',
            fn() => $call(5, [], 'Must not write legacy when direct is active'));
        self::assertSame($grantBefore, $this->legacyGrants(),
            'Direct phase must not write legacy permission relations.');
        self::assertSame($directBefore, $this->db->query(
            'SELECT * FROM pa_platform_role_direct_state ORDER BY platform_role_id'
        )->fetchAll(PDO::FETCH_ASSOC));
        // This fixture has not revoked effective privileges after cutover; use
        // Identity's existing safe rollback, not an override or an extra switch.
        $this->writer->rollbackDirectRoleSource(
            $this->actor(11), 'Rollback only after native equivalence guard',
        );
        self::assertSame('legacy', $this->direct->source());
        $restored = $call(5, [], 'Legacy writes available after guarded rollback');
        self::assertSame('6', $restored['revision']);
        self::assertSame([], $restored['permission_keys']);
        self::assertTrue($store->isEmpty());
    }

    public function testRoleRevisionCatalogDriftAndCrossAudienceAbortBeforeWrites(): void
    {
        $candidate = $this->completeCandidate();
        $digest = $this->direct->catalogDigest($candidate['nodes']);
        $resolver = $this->trustedSource($candidate['nodes']);
        $before = $this->legacyGrants();
        $this->db->exec("UPDATE pa_system_api SET status='retired' WHERE id='" . str_repeat('a', 64) . "'");
        $this->assertRejected('PLATFORM_ROLE_API_CATALOG_DRIFT', fn() => $this->writer->replaceRoleNodes(
            $this->actor(11), 201, ['platform.fixture.page'], 1, $digest, 'Rejected drift', $resolver,
        ));
        self::assertSame(0, (int) $this->db->query('SELECT COUNT(*) FROM pa_platform_role_direct_state')->fetchColumn());
        $this->db->exec("UPDATE pa_system_api SET status='active' WHERE id='" . str_repeat('a', 64) . "'");
        $foreignNodes = [['key' => 'platform.fixture.foreign', 'parent_key' => null, 'type' => 'operation',
            'api_ids' => [str_repeat('e', 64)]]];
        $foreignDigest = $this->direct->catalogDigest($foreignNodes);
        $this->assertRejected('PLATFORM_ROLE_API_CATALOG_DRIFT', fn() => $this->writer->replaceRoleNodes(
            $this->actor(11), 201, ['platform.fixture.foreign'], 1, $foreignDigest, 'Wrong audience',
            $this->trustedSource($foreignNodes),
        ));
        $this->writer->replaceRoleNodes(
            $this->actor(11), 201, ['platform.fixture.page'], 1, $digest, 'First explicit save', $resolver,
        );
        $snapshot = $this->db->query('SELECT * FROM pa_platform_role_direct_state')->fetchAll(PDO::FETCH_ASSOC);
        $this->assertRejected('REVISION_MISMATCH', fn() => $this->writer->replaceRoleNodes(
            $this->actor(11), 201, ['platform.fixture.page'], 1, $digest, 'Stale revision', $resolver,
        ));
        self::assertSame($snapshot, $this->db->query('SELECT * FROM pa_platform_role_direct_state')->fetchAll(PDO::FETCH_ASSOC));
        self::assertSame($before, $this->legacyGrants());
    }

    public function testNativeHttpContractPrepareSaveExplicitEmptyAndRejections(): void
    {
        $this->db->exec(<<<'SQL'
            INSERT INTO pa_permission (id,"key",status,api_configured)
            VALUES (98,'platform.role.permission.assign','active',0);
            INSERT INTO pa_platform_role_permission (platform_role_id,permission_id) VALUES (101,98);
            SQL);
        $contract = new PlatformRoleNodeWriteContract(
            new PlatformAuthorizationEvaluator($this->roles, new RevisionPermissionCache()),
            $this->writer, $this->direct,
        );
        $actor = $this->actor(11);
        $nodes = $this->completeCandidate()['nodes'];
        $resolver = $this->trustedSource($nodes);
        $prepared = $contract->prepare($actor, [
            'role_id' => '201', 'node_keys' => ['platform.fixture.page'],
        ], $resolver);
        self::assertSame('201', $prepared['role_id']);
        self::assertSame('1', $prepared['expected_revision']);
        self::assertSame('legacy', $prepared['source_mode']);
        self::assertSame($this->direct->catalogDigest($nodes), $prepared['expected_catalog_sha256']);
        $this->assertRejected('PLATFORM_ROLE_REQUEST_INVALID', fn() => $contract->prepare(
            $actor, ['role_id' => 201, 'node_keys' => [], 'nodes' => [['key' => 'forged']]], $resolver,
        ));
        $this->assertRejected('PLATFORM_ROLE_NODES_INVALID', fn() => $contract->prepare(
            $actor, ['role_id' => 201, 'node_keys' => ['platform.fixture.page', 'platform.fixture.page']], $resolver,
        ));
        $this->assertRejected('PLATFORM_ROLE_REQUEST_INVALID', fn() => $contract->save(
            $actor, ['role_id' => '201', 'node_keys' => ['platform.fixture.page']], $resolver,
        ));
        $legacy = $this->legacyGrants();
        $result = $contract->save($actor, [
            'role_id' => $prepared['role_id'], 'expected_revision' => $prepared['expected_revision'],
            'node_keys' => $prepared['node_keys'],
            'expected_catalog_sha256' => $prepared['expected_catalog_sha256'],
            'change_reason' => 'Prepared source-only candidate',
        ], $resolver);
        self::assertSame('2', $result['revision']);
        self::assertSame($prepared['expected_catalog_sha256'], $result['catalog_sha256']);
        self::assertSame($legacy, $this->legacyGrants(), 'The adapter must never dual-write the old grants.');
        $this->assertRejected('REVISION_MISMATCH', fn() => $contract->save($actor, [
            'role_id' => '201', 'node_keys' => ['platform.fixture.page'], 'expected_revision' => '1',
            'expected_catalog_sha256' => $prepared['expected_catalog_sha256'],
            'change_reason' => 'Stale repeated POST',
        ], $resolver));
        $cleared = $contract->prepare($actor, ['role_id' => 201, 'node_keys' => []], $resolver);
        self::assertSame([], $cleared['node_keys'], 'Explicit empty must remain distinct from missing.');
        $result = $contract->save($actor, [
            'role_id' => 201, 'node_keys' => [], 'expected_revision' => $cleared['expected_revision'],
            'expected_catalog_sha256' => $cleared['expected_catalog_sha256'],
            'change_reason' => 'Explicit empty stage',
        ], $resolver);
        self::assertSame(1, (int) $this->db->query(
            'SELECT explicit_empty FROM pa_platform_role_direct_state WHERE platform_role_id=201',
        )->fetchColumn());
        self::assertSame('3', $result['revision']);
        self::assertSame($legacy, $this->legacyGrants());
        $stale = $contract->prepare($actor, ['role_id' => 201, 'node_keys' => ['platform.fixture.page']], $resolver);
        $this->db->exec("UPDATE pa_permission SET status='retired' WHERE id=11");
        $this->assertRejected('PLATFORM_ROLE_API_CATALOG_DRIFT', fn() => $contract->save($actor, [
            'role_id' => 201, 'node_keys' => ['platform.fixture.page'],
            'expected_revision' => $stale['expected_revision'],
            'expected_catalog_sha256' => $stale['expected_catalog_sha256'],
            'change_reason' => 'Stale catalog stage',
        ], $resolver));
        self::assertSame('3', (string) $this->db->query('SELECT revision FROM pa_platform_role WHERE id=201')->fetchColumn());
        self::assertSame($legacy, $this->legacyGrants());
    }

    public function testNativeHttpWriteContractRequiresIndependentMutationPermission(): void
    {
        $contract = new PlatformRoleNodeWriteContract(
            new PlatformAuthorizationEvaluator($this->roles, new RevisionPermissionCache()),
            $this->writer, $this->direct,
        );
        $actor = $this->actor(11);
        $resolver = $this->trustedSource($this->completeCandidate()['nodes']);
        $prepared = $contract->prepare($actor, ['role_id' => 201, 'node_keys' => []], $resolver);
        self::assertSame('1', $prepared['expected_revision']);
        try {
            $contract->save($actor, [
                'role_id' => 201, 'node_keys' => [], 'expected_revision' => '1',
                'expected_catalog_sha256' => $prepared['expected_catalog_sha256'],
                'change_reason' => 'No mutation authority',
            ], $resolver);
            self::fail('Missing platform.role.permission.assign must deny the source write.');
        } catch (AuthorizationException) {
            self::assertSame(0, (int) $this->db->query(
                'SELECT COUNT(*) FROM pa_platform_role_direct_state',
            )->fetchColumn());
        }
    }

    public function testIncompleteMigrationCannotSwitchTheSingleGlobalSource(): void
    {
        $candidate = $this->completeCandidate();
        $digest = $this->direct->catalogDigest($candidate['nodes']);
        $resolver = $this->trustedSource($candidate['nodes']);
        $this->writer->replaceRoleNodes(
            $this->actor(11), 201, ['platform.fixture.page'], 1, $digest, 'Single staged role', $resolver,
        );
        $auditBefore = (int) $this->db->query('SELECT COUNT(*) FROM pa_platform_audit_event')->fetchColumn();
        $this->assertRejected('PLATFORM_DIRECT_CATALOG_INCOMPLETE', fn() => $this->writer->activateDirectRoleSource(
            $this->actor(11), $this->preview, $this->modules, $digest, 'Missing roles', $resolver,
        ));
        self::assertSame('legacy', $this->direct->source());
        self::assertSame($auditBefore, (int) $this->db->query('SELECT COUNT(*) FROM pa_platform_audit_event')->fetchColumn());
        self::assertSame(1, (int) $this->db->query('SELECT COUNT(*) FROM pa_platform_role_direct_state')->fetchColumn());
    }

    public function testPermissionStateDriftRejectsStaleNodeSaveWithoutRevisionAuditOrGrants(): void
    {
        $candidate = $this->completeCandidate();
        $digest = $this->direct->catalogDigest($candidate['nodes']);
        $beforeRevision = $this->db->query('SELECT revision FROM pa_platform_role WHERE id=201')->fetchColumn();
        $beforeSecurity = $this->db->query('SELECT security_revision FROM pa_platform_operator WHERE id=22')->fetchColumn();
        $beforeLegacy = $this->legacyGrants();
        $beforeAudit = (int) $this->db->query('SELECT COUNT(*) FROM pa_platform_audit_event')->fetchColumn();
        $this->db->exec("UPDATE pa_permission SET status='retired' WHERE id=11");
        $this->assertRejected('PLATFORM_ROLE_API_CATALOG_DRIFT', fn() => $this->writer->replaceRoleNodes(
            $this->actor(11), 201, ['platform.fixture.page'], 1, $digest,
            'Permission was retired after draft preview', $this->trustedSource($candidate['nodes']),
        ));
        self::assertSame(0, (int) $this->db->query('SELECT COUNT(*) FROM pa_platform_role_direct_state')->fetchColumn());
        self::assertSame($beforeRevision, $this->db->query('SELECT revision FROM pa_platform_role WHERE id=201')->fetchColumn());
        self::assertSame($beforeSecurity, $this->db->query('SELECT security_revision FROM pa_platform_operator WHERE id=22')->fetchColumn());
        self::assertSame($beforeLegacy, $this->legacyGrants());
        self::assertSame($beforeAudit, (int) $this->db->query('SELECT COUNT(*) FROM pa_platform_audit_event')->fetchColumn());
    }

    public function testArchivedLegacyRoleIsInertButDoesNotPermanentlyBlockCompleteCutover(): void
    {
        $candidate = $this->completeCandidate();
        $digest = $this->direct->catalogDigest($candidate['nodes']);
        $resolver = $this->trustedSource($candidate['nodes']);
        foreach ($candidate['role_assignments'] as $assignment) {
            $this->writer->replaceRoleNodes(
                $this->actor(11), (int) $assignment['role_id'], $assignment['node_keys'],
                (int) $assignment['expected_revision'], $digest, 'Complete active roles', $resolver,
            );
        }
        // A real prior role archive keeps its historical permission links.
        // It cannot be reactivated through the role management contract.
        $this->db->exec(<<<'SQL'
            INSERT INTO pa_platform_role (id,"key",status,is_builtin,revision)
            VALUES (601,'platform.fixture.archived','archived',0,4);
            INSERT INTO pa_platform_role_permission (platform_role_id,permission_id)
            VALUES (601,11);
            INSERT INTO pa_platform_operator_role (platform_operator_id,platform_role_id)
            VALUES (44,601);
            SQL);
        $beforeLegacy = $this->legacyGrants();
        self::assertNotContains('platform.fixture.read', $this->roles->permissions(44)->keys());
        $this->writer->activateDirectRoleSource(
            $this->actor(11), $this->preview, $this->modules, $digest,
            'Archived role is inert', $resolver,
        );
        self::assertSame('direct', $this->direct->source());
        self::assertSame(1, (int) $this->db->query(
            'SELECT explicit_empty FROM pa_platform_role_direct_state WHERE platform_role_id=601',
        )->fetchColumn());
        self::assertSame([], $this->direct->rolePermissionKeys(601, 4));
        self::assertNotContains('platform.fixture.read', $this->roles->permissions(44)->keys());
        self::assertSame($beforeLegacy, $this->legacyGrants());
        $this->writer->rollbackDirectRoleSource($this->actor(11), 'Keep archive inactive');
        self::assertNotContains('platform.fixture.read', $this->roles->permissions(44)->keys());
    }

    public function testCatalogChangeBeforeGlobalSwitchRollsBackWithoutActivatingDirectSource(): void
    {
        $candidate = $this->completeCandidate();
        $digest = $this->direct->catalogDigest($candidate['nodes']);
        $resolver = $this->trustedSource($candidate['nodes']);
        foreach ($candidate['role_assignments'] as $assignment) {
            $this->writer->replaceRoleNodes(
                $this->actor(11), (int) $assignment['role_id'], $assignment['node_keys'],
                (int) $assignment['expected_revision'], $digest, 'Stable staged role', $resolver,
            );
        }
        $beforeSecurity = $this->db->query('SELECT security_revision FROM pa_platform_operator WHERE id=22')->fetchColumn();
        $beforeAudit = (int) $this->db->query('SELECT COUNT(*) FROM pa_platform_audit_event')->fetchColumn();
        $beforeLegacy = $this->legacyGrants();
        $this->db->exec("UPDATE pa_permission SET status='retired' WHERE id=11");
        $this->assertRejected('PLATFORM_ROLE_API_CATALOG_DRIFT', fn() => $this->writer->activateDirectRoleSource(
            $this->actor(11), $this->preview, $this->modules, $digest, 'Reject stale source flip', $resolver,
        ));
        self::assertSame('legacy', $this->direct->source());
        self::assertSame($beforeSecurity, $this->db->query('SELECT security_revision FROM pa_platform_operator WHERE id=22')->fetchColumn());
        self::assertSame($beforeAudit, (int) $this->db->query('SELECT COUNT(*) FROM pa_platform_audit_event')->fetchColumn());
        self::assertSame($beforeLegacy, $this->legacyGrants());
        self::assertSame(7, (int) $this->db->query('SELECT COUNT(*) FROM pa_platform_role_direct_state')->fetchColumn());
    }

    public function testLastControlAdministratorGuardRollsBackDirectRevocation(): void
    {
        [$digest, $resolver] = $this->activateCompleteFixture();
        // Remove the only Bootstrap Owner from active operators in the SQLite
        // fixture. The attempted direct mutation must fail and roll back.
        $this->db->exec("UPDATE pa_platform_operator SET status='suspended' WHERE id=33");
        $beforeRole = $this->db->query('SELECT revision FROM pa_platform_role WHERE id=201')->fetchColumn();
        $beforeNode = $this->db->query('SELECT * FROM pa_platform_role_node_api WHERE platform_role_id=201 ORDER BY api_id')->fetchAll(PDO::FETCH_ASSOC);
        $beforeState = $this->db->query('SELECT * FROM pa_platform_role_direct_state WHERE platform_role_id=201')->fetchAll(PDO::FETCH_ASSOC);
        $beforeAudit = (int) $this->db->query('SELECT COUNT(*) FROM pa_platform_audit_event')->fetchColumn();
        $beforeSecurity = $this->db->query('SELECT security_revision FROM pa_platform_operator WHERE id=22')->fetchColumn();
        $this->assertRejected('PLATFORM_CONTROL_ADMIN_REQUIRED', fn() => $this->writer->replaceRoleNodes(
            $this->actor(11), 201, [], 2, $digest, 'Must preserve last admin', $resolver,
        ));
        self::assertSame($beforeRole, $this->db->query('SELECT revision FROM pa_platform_role WHERE id=201')->fetchColumn());
        self::assertSame($beforeNode, $this->db->query('SELECT * FROM pa_platform_role_node_api WHERE platform_role_id=201 ORDER BY api_id')->fetchAll(PDO::FETCH_ASSOC));
        self::assertSame($beforeState, $this->db->query('SELECT * FROM pa_platform_role_direct_state WHERE platform_role_id=201')->fetchAll(PDO::FETCH_ASSOC));
        self::assertSame($beforeAudit, (int) $this->db->query('SELECT COUNT(*) FROM pa_platform_audit_event')->fetchColumn());
        self::assertSame($beforeSecurity, $this->db->query('SELECT security_revision FROM pa_platform_operator WHERE id=22')->fetchColumn());
        self::assertSame('direct', $this->direct->source());
    }

    public function testOverlappingTenantRoleIdNeverWritesTenantRoleOrPermissionRows(): void
    {
        PlatformRoleAuthorizationCatalogAuditFixture::enableTenantRoles($this->db);
        $tenant = $this->db->query('SELECT * FROM pa_role_permission ORDER BY role_id')->fetchAll(PDO::FETCH_ASSOC);
        $candidate = $this->completeCandidate();
        $digest = $this->direct->catalogDigest($candidate['nodes']);
        $this->writer->replaceRoleNodes(
            $this->actor(11), 501, ['platform.fixture.page', 'platform.fixture.write', 'platform.fixture.operation'],
            7, $digest, 'Platform role shares an id with Tenant 71', $this->trustedSource($candidate['nodes']),
        );
        self::assertSame($tenant, $this->db->query('SELECT * FROM pa_role_permission ORDER BY role_id')->fetchAll(PDO::FETCH_ASSOC));
        self::assertSame(1, (int) $this->db->query('SELECT COUNT(*) FROM pa_platform_role_direct_state WHERE platform_role_id=501')->fetchColumn());
        self::assertSame('legacy', $this->direct->source());
    }

    public function testRealCodeRegisteredNodeProducerFeedsFormalIdentitySave(): void
    {
        $codeRoot = getenv('PEANUT_G18_CODE_ROOT');
        if (!is_string($codeRoot) || !is_dir($codeRoot . '/server/generated')) {
            self::markTestSkipped('Real Code source is an explicit cross-repository fixture input.');
        }
        $module = json_decode(
            (string) file_get_contents(dirname(__DIR__, 3) . '/server/app/modules/peanut/identity/module.json'),
            true, 64, JSON_THROW_ON_ERROR,
        );
        self::assertContains(PlatformAccessAdminService::class, $module['contracts']['exports'],
            'Identity writer must remain an official public module service, not a host-private adapter.');
        self::assertContains(PlatformRoleNodeWriteContract::class, $module['contracts']['exports'],
            'The source-only HTTP prepare/save contract must be explicitly exported by Identity.');
        self::assertSame('database/migrations', $module['backend']['migrations']);
        $provider = (string) file_get_contents($codeRoot . '/server/app/AppService.php');
        self::assertStringContainsString(
            'bind(PlatformAuthorizationRepository::class, ThinkPhpPlatformAuthorizationRepository::class)',
            $provider, 'Real Platform sessions must use the same Identity authorization repository.',
        );
        $route = (string) file_get_contents($codeRoot . '/server/route/platform.php');
        self::assertStringContainsString("Route::post('roles/permissions/catalog-audit'", $route);
        self::assertStringContainsString("Route::post('roles/permissions/replace'", $route);
        self::assertStringContainsString("Route::post('roles/nodes/prepare'", $route,
            'Official Code must register the guarded native preparation route.');
        self::assertStringContainsString("Route::post('roles/nodes/save'", $route,
            'Official Code must register the guarded direct-node save route.');
        $validator = (string) file_get_contents($codeRoot . '/server/app/platform/validate/PlatformAccessValidate.php');
        self::assertStringContainsString("'replaceRolePermissions' => ['role_id', 'permission_keys'", $validator,
            'The existing HTTP write contract must remain a legacy permission-key request.');
        require_once $codeRoot . '/server/app/common/services/authorization/GeneratedApiCatalog.php';
        require_once $codeRoot . '/server/app/platform/query/PlatformRoleNodeSource.php';
        require_once $codeRoot . '/server/app/platform/controller/PlatformRoleNodeWriteController.php';
        $catalog = json_decode((string) file_get_contents($codeRoot . '/server/generated/api-catalog.json'), true, 128, JSON_THROW_ON_ERROR);
        $official = json_decode((string) file_get_contents($codeRoot . '/server/generated/platform-role-source.json'), true, 128, JSON_THROW_ON_ERROR);
        $roleRoutes = array_values(array_filter($catalog['endpoints'], static fn(array $endpoint): bool =>
            $endpoint['method'] === 'POST' &&
            in_array($endpoint['path'], [
                '/platformapi/roles/permissions/preview',
                '/platformapi/roles/permissions/catalog-audit',
                '/platformapi/roles/permissions/replace',
            ], true),
        ));
        self::assertCount(3, $roleRoutes, 'Native API catalog must retain both read-only admission routes and the legacy writer.');
        $indexedRoutes = array_column($roleRoutes, null, 'path');
        self::assertSame(
            ['platform.permission.api.read', 'platform.operator.read'],
            $indexedRoutes['/platformapi/roles/permissions/catalog-audit']['prerequisite_permission_keys'],
        );
        self::assertSame(
            ['platform.role.read'],
            $indexedRoutes['/platformapi/roles/permissions/catalog-audit']['default_permission_keys'],
        );
        self::assertSame(
            ['platform.role.permission.assign'],
            $indexedRoutes['/platformapi/roles/permissions/replace']['default_permission_keys'],
        );
        self::assertSame([], array_values(array_filter($catalog['endpoints'], static fn(array $endpoint): bool =>
            str_starts_with($endpoint['path'], '/platformapi/roles/permissions/nodes'),
        )), 'An unapproved direct-node write operation must not enter the native API contract.');
        $apiInsert = $this->db->prepare(<<<'SQL'
            INSERT INTO pa_system_api (id,method,path,audience,access_mode,status,module_key,default_permission_keys,prerequisite_permission_keys)
            VALUES (?,?,?,?,?,?,?,?,?)
            SQL);
        foreach ($catalog['endpoints'] as $endpoint) {
            if ($endpoint['audience'] !== 'platform') {
                continue;
            }
            $owner = $endpoint['owner']['type'] === 'module' ? $endpoint['owner']['key'] : 'platform';
            $apiInsert->execute([
                $endpoint['api_id'], $endpoint['method'], $endpoint['path'], 'platform',
                $endpoint['auth']['mode'], 'active', $owner,
                json_encode($endpoint['default_permission_keys'], JSON_THROW_ON_ERROR),
                json_encode($endpoint['prerequisite_permission_keys'], JSON_THROW_ON_ERROR),
            ]);
        }
        foreach (['source', 'module_key', 'parent_key', 'route_path', 'client_keys_json'] as $column) {
            $this->db->exec('ALTER TABLE pa_system_menu ADD COLUMN ' . $column . ' TEXT');
        }
        $menuInsert = $this->db->prepare(<<<'SQL'
            INSERT INTO pa_system_menu (id,"key",scope,type,status,is_disable,source,module_key,parent_key,route_path,client_keys_json)
            VALUES (?,?,?,?,?,?,?,?,?,?,?)
            SQL);
        $id = 100;
        foreach ($official['nodes'] as $node) {
            if (!in_array($node['type'], ['group', 'page'], true)) {
                continue;
            }
            $menuInsert->execute([
                ++$id, $node['key'], 'platform', $node['type'], 'active', 0,
                in_array($node['key'], ['platform.tenancy', 'platform.tenant.list'], true) ? 'system' : 'module',
                'platform', $node['parent_key'], $node['route_path'], '["platform-web"]',
            ]);
        }
        $this->db->exec("INSERT INTO pa_permission (id,\"key\",status,api_configured) VALUES (100,'platform.tenant.read','active',0)");
        $route = array_values(array_filter($catalog['endpoints'], static fn(array $endpoint): bool =>
            $endpoint['method'] === 'GET' && $endpoint['path'] === '/platformapi/tenants'
                && $endpoint['audience'] === 'platform'));
        self::assertCount(1, $route);
        $this->db->prepare('INSERT INTO pa_permission_api (permission_id,api_id) VALUES (100,?)')
            ->execute([$route[0]['api_id']]);
        $this->db->exec(<<<'SQL'
            INSERT INTO pa_permission (id,"key",status,api_configured)
            VALUES (98,'platform.role.permission.assign','active',0);
            INSERT INTO pa_platform_role_permission (platform_role_id,permission_id) VALUES (101,98);
            SQL);
        $source = new \app\platform\query\PlatformRoleNodeSource();
        $request = ['role_ids' => [201], 'role_assignments' => [
            ['role_id' => 201, 'expected_revision' => 1, 'node_keys' => ['platform.tenant.list']],
        ]];
        $this->assertRejected('PLATFORM_ROLE_CANDIDATE_INVALID', fn() => $source->authoritativeCandidate([
            ...$request, 'nodes' => [['key' => 'forged']],
        ]));
        $this->assertRejected('PLATFORM_ROLE_NODE_NOT_REGISTERED', fn() => $source->authoritativeCandidate([
            'role_ids' => [201], 'role_assignments' => [[
                'role_id' => 201, 'expected_revision' => 1, 'node_keys' => ['platform.forbidden-node'],
            ]],
        ]));
        $resolved = $source->authoritativeCandidate($request);
        self::assertGreaterThan(50, count($resolved['nodes']));
        self::assertNotContains('platform.tenant.dictionary-catalog.read', array_column($resolved['nodes'], 'key'),
            'Uninstalled ReferenceCodes must not contribute a Platform role API node');
        $this->db->exec("UPDATE pa_system_menu SET source='untrusted' WHERE `key`='platform.tenancy'");
        $this->assertRejected('PLATFORM_ROLE_MENU_CATALOG_DRIFT',
            fn(): array => $source->authoritativeCandidate($request));
        $this->db->exec("UPDATE pa_system_menu SET source='system' WHERE `key`='platform.tenancy'");
        $digest = $this->direct->catalogDigest($resolved['nodes']);
        $legacy = $this->legacyGrants();
        $contract = new PlatformRoleNodeWriteContract(
            new PlatformAuthorizationEvaluator($this->roles, new RevisionPermissionCache()),
            $this->writer, $this->direct,
        );
        $prepared = $contract->prepare($this->actor(11), [
            'role_id' => '201', 'node_keys' => ['platform.tenant.list'],
        ], [$source, 'authoritativeCandidate']);
        self::assertSame('1', $prepared['expected_revision']);
        self::assertSame($digest, $prepared['expected_catalog_sha256']);
        self::assertSame('legacy', $prepared['source_mode']);
        $result = $contract->save($this->actor(11), [
            'role_id' => $prepared['role_id'], 'node_keys' => $prepared['node_keys'],
            'expected_revision' => $prepared['expected_revision'],
            'expected_catalog_sha256' => $prepared['expected_catalog_sha256'],
            'change_reason' => 'Official Code source via public Identity contract',
        ], [$source, 'authoritativeCandidate']);
        self::assertSame(['platform.tenant.list'], $result['node_keys']);
        self::assertSame('2', $result['revision'], 'The real Identity writer must return the updated role CAS revision.');
        self::assertSame($digest, $result['catalog_sha256'], 'The save response binds the exact verified Code source and registered directory.');
        self::assertSame([], $this->direct->rolePermissionKeys(201, 2),
            'Staging a newly declared API must not silently grant a key absent from the old role.');
        self::assertSame($legacy, $this->legacyGrants());
        self::assertSame('legacy', $this->direct->source(), 'Official write remains staged until one explicit source switch.');
    }

    public function testTaskNativeControllerPrepareSaveThroughOfficialSource(): void
    {
        $codeRoot = getenv('PEANUT_G18_CODE_ROOT');
        if (!is_string($codeRoot) || !is_dir($codeRoot . '/server/generated')) {
            self::markTestSkipped('Real Code source is an explicit cross-repository fixture input.');
        }
        $module = json_decode(
            (string) file_get_contents(dirname(__DIR__, 3) . '/server/app/modules/peanut/identity/module.json'),
            true, 64, JSON_THROW_ON_ERROR,
        );
        self::assertContains(PlatformAccessAdminService::class, $module['contracts']['exports'],
            'Identity writer must remain an official public module service, not a host-private adapter.');
        self::assertContains(PlatformRoleNodeWriteContract::class, $module['contracts']['exports'],
            'The source-only HTTP prepare/save contract must be explicitly exported by Identity.');
        self::assertSame('database/migrations', $module['backend']['migrations']);
        $provider = (string) file_get_contents($codeRoot . '/server/app/AppService.php');
        self::assertStringContainsString(
            'bind(PlatformAuthorizationRepository::class, ThinkPhpPlatformAuthorizationRepository::class)',
            $provider, 'Real Platform sessions must use the same Identity authorization repository.',
        );
        $route = (string) file_get_contents($codeRoot . '/server/route/platform.php');
        self::assertStringContainsString("Route::post('roles/permissions/catalog-audit'", $route);
        self::assertStringContainsString("Route::post('roles/permissions/replace'", $route);
        self::assertStringContainsString("Route::post('roles/nodes/prepare'", $route,
            'Official Code must register the guarded native preparation route.');
        self::assertStringContainsString("Route::post('roles/nodes/save'", $route,
            'Official Code must register the guarded direct-node save route.');
        $validator = (string) file_get_contents($codeRoot . '/server/app/platform/validate/PlatformAccessValidate.php');
        self::assertStringContainsString("'replaceRolePermissions' => ['role_id', 'permission_keys'", $validator,
            'The existing HTTP write contract must remain a legacy permission-key request.');
        require_once $codeRoot . '/server/app/common/services/authorization/GeneratedApiCatalog.php';
        require_once $codeRoot . '/server/app/platform/query/PlatformRoleNodeSource.php';
        require_once $codeRoot . '/server/app/platform/controller/PlatformRoleNodeWriteController.php';
        $catalog = json_decode((string) file_get_contents($codeRoot . '/server/generated/api-catalog.json'), true, 128, JSON_THROW_ON_ERROR);
        $official = json_decode((string) file_get_contents($codeRoot . '/server/generated/platform-role-source.json'), true, 128, JSON_THROW_ON_ERROR);
        $roleRoutes = array_values(array_filter($catalog['endpoints'], static fn(array $endpoint): bool =>
            $endpoint['method'] === 'POST' &&
            in_array($endpoint['path'], [
                '/platformapi/roles/permissions/preview',
                '/platformapi/roles/permissions/catalog-audit',
                '/platformapi/roles/permissions/replace',
            ], true),
        ));
        self::assertCount(3, $roleRoutes, 'Native API catalog must retain both read-only admission routes and the legacy writer.');
        $indexedRoutes = array_column($roleRoutes, null, 'path');
        self::assertSame(
            ['platform.permission.api.read', 'platform.operator.read'],
            $indexedRoutes['/platformapi/roles/permissions/catalog-audit']['prerequisite_permission_keys'],
        );
        self::assertSame(
            ['platform.role.read'],
            $indexedRoutes['/platformapi/roles/permissions/catalog-audit']['default_permission_keys'],
        );
        self::assertSame(
            ['platform.role.permission.assign'],
            $indexedRoutes['/platformapi/roles/permissions/replace']['default_permission_keys'],
        );
        self::assertSame([], array_values(array_filter($catalog['endpoints'], static fn(array $endpoint): bool =>
            str_starts_with($endpoint['path'], '/platformapi/roles/permissions/nodes'),
        )), 'An unapproved direct-node write operation must not enter the native API contract.');
        $apiInsert = $this->db->prepare(<<<'SQL'
            INSERT INTO pa_system_api (id,method,path,audience,access_mode,status,module_key,default_permission_keys,prerequisite_permission_keys)
            VALUES (?,?,?,?,?,?,?,?,?)
            SQL);
        foreach ($catalog['endpoints'] as $endpoint) {
            if ($endpoint['audience'] !== 'platform') {
                continue;
            }
            $owner = $endpoint['owner']['type'] === 'module' ? $endpoint['owner']['key'] : 'platform';
            $apiInsert->execute([
                $endpoint['api_id'], $endpoint['method'], $endpoint['path'], 'platform',
                $endpoint['auth']['mode'], 'active', $owner,
                json_encode($endpoint['default_permission_keys'], JSON_THROW_ON_ERROR),
                json_encode($endpoint['prerequisite_permission_keys'], JSON_THROW_ON_ERROR),
            ]);
        }
        foreach (['source', 'module_key', 'parent_key', 'route_path', 'client_keys_json'] as $column) {
            $this->db->exec('ALTER TABLE pa_system_menu ADD COLUMN ' . $column . ' TEXT');
        }
        $menuInsert = $this->db->prepare(<<<'SQL'
            INSERT INTO pa_system_menu (id,"key",scope,type,status,is_disable,source,module_key,parent_key,route_path,client_keys_json)
            VALUES (?,?,?,?,?,?,?,?,?,?,?)
            SQL);
        $id = 100;
        foreach ($official['nodes'] as $node) {
            if (!in_array($node['type'], ['group', 'page'], true)) {
                continue;
            }
            $menuInsert->execute([
                ++$id, $node['key'], 'platform', $node['type'], 'active', 0,
                in_array($node['key'], ['platform.tenancy', 'platform.tenant.list'], true) ? 'system' : 'module',
                'platform', $node['parent_key'], $node['route_path'], '["platform-web"]',
            ]);
        }
        $this->db->exec("INSERT INTO pa_permission (id,\"key\",status,api_configured) VALUES (100,'platform.tenant.read','active',0)");
        $route = array_values(array_filter($catalog['endpoints'], static fn(array $endpoint): bool =>
            $endpoint['method'] === 'GET' && $endpoint['path'] === '/platformapi/tenants'
                && $endpoint['audience'] === 'platform'));
        self::assertCount(1, $route);
        $this->db->prepare('INSERT INTO pa_permission_api (permission_id,api_id) VALUES (100,?)')
            ->execute([$route[0]['api_id']]);
        $this->db->exec(<<<'SQL'
            INSERT INTO pa_permission (id,"key",status,api_configured)
            VALUES (98,'platform.role.permission.assign','active',0);
            INSERT INTO pa_platform_role_permission (platform_role_id,permission_id) VALUES (101,98);
            SQL);
        $source = new \app\platform\query\PlatformRoleNodeSource();
        $request = ['role_ids' => [201], 'role_assignments' => [
            ['role_id' => 201, 'expected_revision' => 1, 'node_keys' => ['platform.tenant.list']],
        ]];
        $this->assertRejected('PLATFORM_ROLE_CANDIDATE_INVALID', fn() => $source->authoritativeCandidate([
            ...$request, 'nodes' => [['key' => 'forged']],
        ]));
        $this->assertRejected('PLATFORM_ROLE_NODE_NOT_REGISTERED', fn() => $source->authoritativeCandidate([
            'role_ids' => [201], 'role_assignments' => [[
                'role_id' => 201, 'expected_revision' => 1, 'node_keys' => ['platform.forbidden-node'],
            ]],
        ]));
        $resolved = $source->authoritativeCandidate($request);
        self::assertGreaterThan(50, count($resolved['nodes']));
        self::assertNotContains('platform.tenant.dictionary-catalog.read', array_column($resolved['nodes'], 'key'),
            'Uninstalled ReferenceCodes must not contribute a Platform role API node');
        $this->db->exec("UPDATE pa_system_menu SET source='untrusted' WHERE `key`='platform.tenancy'");
        $this->assertRejected('PLATFORM_ROLE_MENU_CATALOG_DRIFT',
            fn(): array => $source->authoritativeCandidate($request));
        $this->db->exec("UPDATE pa_system_menu SET source='system' WHERE `key`='platform.tenancy'");
        $digest = $this->direct->catalogDigest($resolved['nodes']);
        $legacy = $this->legacyGrants();
        $contract = new PlatformRoleNodeWriteContract(
            new PlatformAuthorizationEvaluator($this->roles, new RevisionPermissionCache()),
            $this->writer, $this->direct,
        );
        // Native ThinkPHP request and controller invocation; no web server/listener.
        $store = new \app\common\execution\ExecutionContextStore();
        $this->taskApp->instance(\app\common\execution\CurrentExecutionContext::class,
            new \app\common\execution\CurrentExecutionContext($store));
        // Preserve the generated route and prerequisite metadata as the native
        // in-process boundary, not an ad hoc authorization shortcut.
        foreach (['/platformapi/roles/nodes/prepare', '/platformapi/roles/nodes/save',
                  '/platformapi/roles/permissions/replace'] as $path) {
            $row = array_values(array_filter($catalog['endpoints'],
                static fn(array $item): bool => $item['path'] === $path && $item['method'] === 'POST'));
            self::assertCount(1, $row, $path);
            $default = $row[0]['default_permission_keys'];
            foreach ($default as $key) {
                $stmt = $this->db->prepare('SELECT id FROM pa_permission WHERE "key"=?');
                $stmt->execute([$key]);
                $permissionId = $stmt->fetchColumn();
                self::assertNotFalse($permissionId, $key);
                $exists = $this->db->prepare('SELECT COUNT(*) FROM pa_permission_api WHERE permission_id=? AND api_id=?');
                $exists->execute([(int) $permissionId, $row[0]['api_id']]);
                if ((int) $exists->fetchColumn() === 0) {
                    $this->db->prepare('INSERT INTO pa_permission_api (permission_id,api_id) VALUES (?,?)')
                        ->execute([(int) $permissionId, $row[0]['api_id']]);
                }
            }
        }
        $digest = $this->direct->catalogDigest($source->authoritativeCandidate($request)['nodes']);
        $invoke = function (string $method, array $payload, bool $authenticated = true) use ($store, $contract, $source, $catalog): array {
            $path = 'roles/nodes/' . $method;
            $request = (new \think\Request())->withServer(['REQUEST_METHOD' => 'POST'])->withPost($payload);
            $request->setPathinfo($path);
            $request->setRule((new \think\Route($this->taskApp))->rule($path, static fn() => null, 'POST'));
            $this->taskApp->instance('request', $request);
            $this->taskApp->instance(\think\Request::class, $request);
            $call = function () use ($method, $contract, $source) {
                $controller = new \app\platform\controller\PlatformRoleNodeWriteController($this->taskApp,$contract,$source);
                $result = $controller->{$method}();
                self::assertSame(200, $result->getCode());
                $body = $result->getData();
                self::assertSame(20000, $body['code']);
                return $body['data'];
            };
            if (!$authenticated) return $call();
            $route = array_values(array_filter($catalog['endpoints'], static fn(array $endpoint): bool =>
                $endpoint['path'] === '/platformapi/' . $path && $endpoint['method'] === 'POST'));
            self::assertCount(1, $route);
            $repository = new ThinkPhpPlatformAuthorizationRepository();
            $evaluator = new PlatformAuthorizationEvaluator($repository, new RevisionPermissionCache());
            $grant = new \app\common\services\authorization\EndpointPermissionGrant($request);
            $authorization = new \app\platform\services\PlatformApiAuthorization($evaluator, $grant);
            $sessions = new \app\platform\services\PlatformOperatorSessionService(
                (new ReflectionClass(\PeanutAdmin\Modules\Identity\Auth\PlatformAuthService::class))->newInstanceWithoutConstructor(),
                $authorization, $repository,
            );
            $middleware = new \app\platform\http\middleware\PlatformPermissionMiddleware(
                new \app\common\execution\CurrentExecutionContext($store),
                $sessions,
                new DemoAccountPolicy(false, []),
                new ApiPermissionCatalog(),
                $grant,
                $this->modules,
            );
            $next = fn(\think\Request $request) => $call();
            foreach (array_reverse($route[0]['middleware']) as $entry) {
                if ($entry['class'] !== \app\platform\http\middleware\PlatformPermissionMiddleware::class) {
                    continue; // Login uses a validated Platform fixture session, never production credentials.
                }
                $continue = $next;
                $permission = $entry['arguments'][0];
                $next = fn(\think\Request $request) => $middleware->handle($request, $continue, $permission);
            }
            $context = new \app\common\execution\PlatformExecutionContext(
                \app\platform\context\PlatformOperatorContext::fromValidatedPlatformSession($this->actor(11)),
                'g18.test.http'
            );
            return $store->runHttp($context, fn() => $next($request));
        };
        $prepared = $invoke('prepare', ['role_id'=>'201','node_keys'=>['platform.tenant.list']]);
        self::assertSame('1', $prepared['expected_revision']);
        self::assertSame($digest, $prepared['expected_catalog_sha256']);
        self::assertSame('legacy', $prepared['source_mode']);
        $result = $invoke('save', [
            'role_id' => $prepared['role_id'], 'node_keys' => $prepared['node_keys'],
            'expected_revision' => $prepared['expected_revision'],
            'expected_catalog_sha256' => $prepared['expected_catalog_sha256'],
            'change_reason' => 'Official Code source via public Identity contract',
        ]);
        self::assertSame(['platform.tenant.list'], $result['node_keys']);
        self::assertSame('2', $result['revision'], 'The real Identity writer must return the updated role CAS revision.');
        self::assertSame($digest, $result['catalog_sha256'], 'The save response binds the exact verified Code source and registered directory.');
        self::assertSame([], $this->direct->rolePermissionKeys(201, 2),
            'Staging a newly declared API must not silently grant a key absent from the old role.');
        self::assertSame($legacy, $this->legacyGrants());
        self::assertSame('legacy', $this->direct->source(), 'Official write remains staged until one explicit source switch.');
        $this->assertRejected('REVISION_MISMATCH', fn() => $invoke('save', [
            'role_id'=>'201','node_keys'=>['platform.tenant.list'],
            'expected_revision'=>'1',
            'expected_catalog_sha256'=>$prepared['expected_catalog_sha256'],
            'change_reason'=>'Stale prepared request',
        ]));
        $cleared = $invoke('prepare', ['role_id'=>'201','node_keys'=>[]]);
        self::assertSame([], $cleared['node_keys'], 'Explicit empty selection is not a missing field.');
        $result = $invoke('save', [
            'role_id'=>'201','node_keys'=>[],
            'expected_revision'=>$cleared['expected_revision'],
            'expected_catalog_sha256'=>$cleared['expected_catalog_sha256'],
            'change_reason'=>'Explicitly clear role nodes',
        ]);
        self::assertSame('3', $result['revision']);
        self::assertSame(1, (int) $this->db->query(
            'SELECT explicit_empty FROM pa_platform_role_direct_state WHERE platform_role_id=201'
        )->fetchColumn());
        self::assertSame($legacy, $this->legacyGrants(), 'Direct saves must not dual-write legacy permission grants.');
        $this->assertRejected('PLATFORM_ROLE_REQUEST_INVALID', fn() => $invoke('prepare', [
            'role_id'=>'201', 'node_keys'=>[], 'nodes'=>['client-forged'],
        ]));
        $this->assertRejected('PLATFORM_ROLE_REQUEST_INVALID', fn() => $invoke('prepare', [
            'role_id'=>'201',
        ]));
        // The legacy endpoint is tested through the actual PlatformAccessController
        // in testNativeLegacyRoleWriteSurvivesUntilCutoverAndAfterSafeRollback.
        try {
            $invoke('prepare', ['role_id'=>'201','node_keys'=>[]], false);
            self::fail('Unauthenticated native controller must refuse');
        } catch (\app\common\http\ApiProblem $error) {
            self::assertSame(40100, $error->apiCode());
        }
        $current = $invoke('prepare', ['role_id'=>'201','node_keys'=>['platform.tenant.list']]);
        $this->db->exec("UPDATE pa_system_api SET status='retired' WHERE path='/platformapi/tenants'");
        $this->assertRejected('PLATFORM_ROLE_API_CATALOG_DRIFT', fn() => $invoke('save', [
            'role_id'=>'201','node_keys'=>['platform.tenant.list'],
            'expected_revision'=>$current['expected_revision'],
            'expected_catalog_sha256'=>$current['expected_catalog_sha256'],
            'change_reason'=>'Catalog was changed after prepare',
        ]));
        self::assertSame('3', (string) $this->db->query(
            'SELECT revision FROM pa_platform_role WHERE id=201'
        )->fetchColumn());
        // The route must fail inside the native middleware before calling
        // Identity when the current Platform actor loses mutation capability.
        $directBeforeDeny = $this->db->query(
            'SELECT * FROM pa_platform_role_direct_state ORDER BY platform_role_id'
        )->fetchAll(PDO::FETCH_ASSOC);
        $this->db->exec('DELETE FROM pa_platform_role_permission WHERE platform_role_id=101 AND permission_id=98');
        try {
            $invoke('save', [
                'role_id'=>'201','node_keys'=>[],
                'expected_revision'=>'3',
                'expected_catalog_sha256'=>$current['expected_catalog_sha256'],
                'change_reason'=>'Must be rejected by native middleware',
            ]);
            self::fail('Missing mutation permission must cause HTTP 403');
        } catch (\app\common\http\ApiProblem $error) {
            self::assertSame(40300, $error->apiCode());
        }
        self::assertSame($directBeforeDeny, $this->db->query(
            'SELECT * FROM pa_platform_role_direct_state ORDER BY platform_role_id'
        )->fetchAll(PDO::FETCH_ASSOC));
        self::assertTrue($store->isEmpty(), 'No execution context may leak across in-process requests.');

    }

    public function testDirectRoleLifecycleKeepsSourceRevisionAndNewRolesExplicitlyEmpty(): void
    {
        $this->activateCompleteFixture();
        $new = $this->writer->createRole($this->actor(11), 'platform.fixture.new-role', 'New direct role', null);
        $newId = (int) $new['id'];
        self::assertSame([], $this->direct->rolePermissionKeys($newId, 1));
        self::assertSame(1, (int) $this->db->query(
            'SELECT explicit_empty FROM pa_platform_role_direct_state WHERE platform_role_id=' . $newId,
        )->fetchColumn());
        $this->writer->updateRole($this->actor(11), 201, 2, 'Renamed role', null, 'Display-only change');
        self::assertContains('platform.fixture.read', $this->direct->rolePermissionKeys(201, 3));
        $this->writer->archiveRole($this->actor(11), $newId, 1, 'Archive empty role');
        self::assertSame([], $this->direct->rolePermissionKeys($newId, 2));
        self::assertSame('direct', $this->direct->source());
    }

    public function testNewInstanceBundleNeverBecomesAnImplicitDirectGrant(): void
    {
        $this->activateCompleteFixture();
        $before = $this->roles->permissions(22)->keys();
        $this->db->exec(<<<'SQL'
            INSERT INTO pa_permission (id,"key",status,api_configured)
            VALUES (99,'platform.fixture.unapproved','active',0)
            SQL);
        $this->db->prepare('INSERT INTO pa_permission_api (permission_id,api_id) VALUES (?,?)')
            ->execute([99, str_repeat('a', 64)]);
        self::assertEqualsCanonicalizing($before, $this->roles->permissions(22)->keys(),
            'A newly bound API permission must never be granted without an explicit role save.');
        $this->db->prepare('INSERT INTO pa_permission_api (permission_id,api_id) VALUES (?,?)')
            ->execute([13, str_repeat('b', 64)]);
        $this->assertRejected('PLATFORM_DIRECT_API_CATALOG_DRIFT', fn() => $this->direct->rolePermissionKeys(202, 2));
    }

    /** @return array{string,Closure} */
    private function activateCompleteFixture(): array
    {
        $candidate = $this->completeCandidate();
        $digest = $this->direct->catalogDigest($candidate['nodes']);
        $resolver = $this->trustedSource($candidate['nodes']);
        foreach ($candidate['role_assignments'] as $assignment) {
            $this->writer->replaceRoleNodes(
                $this->actor(11), (int) $assignment['role_id'], $assignment['node_keys'],
                (int) $assignment['expected_revision'], $digest, 'Complete fixture stage', $resolver,
            );
        }
        $this->writer->activateDirectRoleSource(
            $this->actor(11), $this->preview, $this->modules, $digest,
            'Complete fixture cutover', $resolver,
        );
        return [$digest, $resolver];
    }

    /** @return array<string,mixed> */
    private function completeCandidate(): array
    {
        $base = PlatformRoleAuthorizationPreviewFixture::exactCandidate();
        $nodes = $base['nodes'];
        $nodes[] = ['key' => 'platform.fixture.owner-operation', 'parent_key' => null, 'type' => 'operation',
            'api_ids' => [str_repeat('1', 64)]];
        return ['nodes' => $nodes, 'role_assignments' => [
            ['role_id' => 101, 'expected_revision' => 1, 'node_keys' => ['platform.fixture.owner-operation']],
            ['role_id' => 201, 'expected_revision' => 1, 'node_keys' => ['platform.fixture.page', 'platform.fixture.write', 'platform.fixture.operation']],
            ['role_id' => 202, 'expected_revision' => 1, 'node_keys' => ['platform.fixture.page']],
            ['role_id' => 301, 'expected_revision' => 1, 'node_keys' => []],
            ['role_id' => 401, 'expected_revision' => 1, 'node_keys' => []],
            ['role_id' => 501, 'expected_revision' => 7, 'node_keys' => ['platform.fixture.page', 'platform.fixture.write', 'platform.fixture.operation']],
            ['role_id' => 502, 'expected_revision' => 3, 'node_keys' => []],
        ]];
    }

    /** @param list<array<string,mixed>> $nodes */
    private function trustedSource(array $nodes): Closure
    {
        return static fn(array $request): array => [
            'role_ids' => $request['role_ids'],
            'role_assignments' => $request['role_assignments'],
            'nodes' => $nodes,
        ];
    }

    private function actor(int $operatorId): PlatformContext
    {
        return PlatformContext::fromValidatedSession(
            new ValidatedPlatformSession(1, 'g18-real-source-fixture', $operatorId * 10 + 1,
                $operatorId, 'platform-web', new DateTimeImmutable('2031-01-01T00:00:00Z')),
            'g18-real-source-fixture',
        );
    }

    /** @return list<array<string,mixed>> */
    private function legacyGrants(): array
    {
        return $this->db->query(
            'SELECT platform_role_id,permission_id FROM pa_platform_role_permission ORDER BY platform_role_id,permission_id',
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    private function assertRejected(string $code, Closure $action): void
    {
        try {
            $action();
            self::fail('Invalid direct authorization mutation was accepted.');
        } catch (AdminAccessException $error) {
            self::assertSame($code, $error->errorCode);
        }
    }
}
