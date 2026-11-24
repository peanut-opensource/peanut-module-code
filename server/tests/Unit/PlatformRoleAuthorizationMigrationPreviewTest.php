<?php

declare(strict_types=1);

use PeanutAdmin\Kernel\Auth\ValidatedPlatformSession;
use PeanutAdmin\Kernel\Authorization\Application\AdminAccessException;
use PeanutAdmin\Kernel\Authorization\AuthorizationException;
use PeanutAdmin\Kernel\Authorization\RevisionPermissionCache;
use PeanutAdmin\Kernel\Context\PlatformContext;
use PeanutAdmin\Kernel\Module\ModuleAvailability;
use PeanutAdmin\Kernel\Module\ModuleException;
use PeanutAdmin\Kernel\Platform\Authorization\PlatformAuthorizationEvaluator;
use PeanutAdmin\Modules\Identity\Authorization\ApiPermissionCatalog;
use PeanutAdmin\Modules\Identity\Contract\PlatformRoleAuthorizationPreview;
use PeanutAdmin\Modules\Identity\Platform\Authorization\ThinkPhpPlatformAuthorizationRepository;
use PeanutAdmin\Modules\Identity\Policy\DemoAccountPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use think\App;

require_once dirname(__DIR__) . '/Support/ThinkPhpTestConnection.php';
require_once dirname(__DIR__) . '/Support/PlatformRoleAuthorizationPreviewFixture.php';

/** Uses the author's actual queries over a process-local SQLite connection. */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class PlatformRoleAuthorizationMigrationPreviewTest extends TestCase
{
    private PDO $database;
    private ThinkPhpPlatformAuthorizationRepository $roles;
    private PlatformAuthorizationEvaluator $authorization;
    private ApiPermissionCatalog $catalog;
    private ModuleAvailability $modules;
    private ?string $temporaryDirectory = null;

    protected function setUp(): void
    {
        $root = dirname(__DIR__, 3);
        $source = (new ReflectionClass(PlatformRoleAuthorizationPreview::class))->getFileName();
        self::assertSame($root . '/server/app/modules/peanut/identity/src/Contract/PlatformRoleAuthorizationPreview.php', $source);
        require_once $root . '/server/vendor/topthink/framework/src/helper.php';
        $temporary = $root . '/.local/tmp/platform-authorization-preview-' . bin2hex(random_bytes(6));
        if (!mkdir($temporary, 0700, true)) {
            throw new RuntimeException('Could not create the platform authorization preview test directory.');
        }
        $this->temporaryDirectory = $temporary;
        $app = new App($temporary);
        $app->config->set(['default' => 'file', 'stores' => ['file' => ['type' => 'File', 'path' => $temporary . '/cache/']]], 'cache');
        $this->database = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        ThinkPhpTestConnection::fromPdo($this->database);
        PlatformRoleAuthorizationPreviewFixture::install($this->database);
        $this->roles = new ThinkPhpPlatformAuthorizationRepository();
        $this->authorization = new PlatformAuthorizationEvaluator($this->roles, new RevisionPermissionCache());
        $this->catalog = new ApiPermissionCatalog();
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

    public function testDirectPageActionAndOperationCoverActualLegacyRelationsAndCapabilitiesWithoutWrites(): void
    {
        $before = $this->database->query('SELECT total_changes()')->fetchColumn();
        $result = $this->preview($this->candidate());
        self::assertSame($before, $this->database->query('SELECT total_changes()')->fetchColumn());
        self::assertSame('22', $result['operator_id']);
        self::assertTrue($result['capability_equal']);
        self::assertTrue($result['relation_coverage_complete']);
        self::assertFalse($result['owner_dynamic']);
        self::assertSame([], $result['unresolved']);
        self::assertSame(['platform.fixture.empty', 'platform.fixture.prerequisite'], $result['non_http_permission_keys']);
        self::assertSame(['201', '202'], array_column($result['roles'], 'role_id'));
        self::assertSame([true, true], array_column($result['roles'], 'relation_equal'));
        self::assertSame([
            ['method' => 'GET', 'path' => '/platformapi/fixture/optional'],
            ['method' => 'GET', 'path' => '/platformapi/fixture/read'],
            ['method' => 'POST', 'path' => '/platformapi/fixture/write'],
        ], $this->catalog->platformAccess($this->roles->permissions(22)->keys(), $this->modules));
    }

    public function testEqualUnionDoesNotHideAnOmittedRoleOrItsLostRelation(): void
    {
        $candidate = $this->candidate();
        array_pop($candidate['role_assignments']);
        $result = $this->preview($candidate);
        self::assertTrue($result['capability_equal']);
        self::assertFalse($result['relation_coverage_complete']);
        self::assertSame([$this->id('a')], $result['roles'][1]['removed']);
        self::assertContains(['code' => 'role_assignment_missing', 'role_id' => '202'], $result['unresolved']);

        $candidate['role_assignments'][] = ['role_id' => 202, 'node_keys' => []];
        $result = $this->preview($candidate);
        self::assertTrue($result['capability_equal']);
        self::assertFalse($result['relation_coverage_complete']);
        self::assertSame([], $result['unresolved']);
    }

    public function testDuplicateLinksAndAssignmentsAreSetOperations(): void
    {
        $candidate = $this->candidate();
        $candidate['nodes'][3]['api_ids'] = [$this->id('c'), $this->id('b')];
        $expected = $this->preview($candidate);
        $candidate['nodes'][] = [
            ...$candidate['nodes'][3], 'api_ids' => [$this->id('b'), $this->id('c'), $this->id('b')],
        ];
        $nodeKeys = array_reverse($candidate['role_assignments'][0]['node_keys']);
        $candidate['role_assignments'][] = ['role_id' => '201', 'node_keys' => [...$nodeKeys, $nodeKeys[0]]];
        self::assertSame($expected, $this->preview($candidate));
    }

    #[DataProvider('conflictingDeclarations')]
    public function testConflictingDuplicateDeclarationsAreInvalid(string $collection, int $index, string $field, array $replacement): void
    {
        $candidate = $this->candidate();
        $candidate[$collection][] = [...$candidate[$collection][$index], $field => $replacement];
        $this->assertInvalid($candidate);
    }

    public static function conflictingDeclarations(): array
    {
        return [
            'explicitly cleared node' => ['nodes', 1, 'api_ids', []],
            'different nonempty node' => ['nodes', 1, 'api_ids', [str_repeat('b', 64)]],
            'explicitly revoked role' => ['role_assignments', 0, 'node_keys', []],
            'different nonempty role' => ['role_assignments', 0, 'node_keys', ['platform.fixture.page']],
        ];
    }

    public function testMissingAndAdditionalFieldsAreRejectedWhileAnExplicitEmptyNodeIsPreserved(): void
    {
        $candidate = $this->candidate();
        $candidate['nodes'][2]['api_ids'] = [];
        $result = $this->preview($candidate);
        self::assertSame([$this->id('b')], $result['removed']);
        self::assertSame([], $result['unresolved']);

        unset($candidate['nodes'][2]['api_ids']);
        $this->assertInvalid($candidate);
        $candidate = $this->candidate();
        unset($candidate['role_assignments'][0]['node_keys']);
        $this->assertInvalid($candidate);
        $this->assertInvalid(['old_permissions' => ['platform.role.read'], ...$this->candidate()]);
        $this->assertInvalid(['operator_id' => '22', 'nodes' => [], 'role_assignments' => [], 'owner_dynamic' => true]);
    }

    public function testSourceDefaultsNeverFillExplicitEmptyOrMissingBindings(): void
    {
        $candidate = $this->candidate();
        $candidate['nodes'][1]['api_ids'][] = $this->id('d');
        $result = $this->preview($candidate);
        self::assertSame([$this->id('d')], $result['added']);
        self::assertSame([], $result['unresolved']);

        $this->database->exec('UPDATE pa_permission SET api_configured=0 WHERE id=14');
        $result = $this->preview($candidate);
        self::assertSame([$this->id('d')], $result['added']);
        self::assertContains([
            'code' => 'legacy_default_not_materialized', 'permission_key' => 'platform.fixture.empty', 'api_id' => $this->id('d'),
        ], $result['unresolved']);
    }

    public function testInvalidGraphAndForeignRetiredOrMissingApisNeverCountAsCoverage(): void
    {
        $candidate = $this->candidate();
        $candidate['nodes'][0]['parent_key'] = 'platform.fixture.page';
        $candidate['nodes'][3]['api_ids'] = [$this->id('e'), $this->id('f'), $this->id('9')];
        $candidate['nodes'][] = ['key' => 'platform.fixture.foreign', 'parent_key' => null, 'type' => 'page', 'api_ids' => [$this->id('a')]];
        $candidate['nodes'][] = ['key' => 'platform.fixture.retired', 'parent_key' => null, 'type' => 'page', 'api_ids' => [$this->id('a')]];
        $candidate['nodes'][] = ['key' => 'platform.fixture.dangling-parent', 'parent_key' => 'platform.fixture.unknown', 'type' => 'action', 'api_ids' => []];
        $candidate['role_assignments'][0]['node_keys'][] = 'platform.fixture.absent';
        $candidate['role_assignments'][] = ['role_id' => '101', 'node_keys' => []];
        $result = $this->preview($candidate);
        self::assertFalse($result['relation_coverage_complete']);
        self::assertSame([], $result['added']);
        self::assertSame([$this->id('a'), $this->id('b'), $this->id('c')], $result['removed']);
        $codes = array_count_values(array_column($result['unresolved'], 'code'));
        self::assertSame(4, $codes['node_parent_invalid']);
        self::assertSame(3, $codes['api_not_active_platform']);
        self::assertSame(2, $codes['registered_node_not_assignable']);
        self::assertSame(1, $codes['role_not_assigned_to_operator']);
        self::assertArrayHasKey('role_node_not_assignable', $codes);
    }

    public function testSelectingAGroupDoesNotGrantChildrenOrPermitDirectGroupBindings(): void
    {
        $candidate = $this->candidate();
        $candidate['role_assignments'][0]['node_keys'] = ['platform.fixture.group'];
        $result = $this->preview($candidate);
        self::assertSame([$this->id('b'), $this->id('c')], $result['removed']);
        self::assertSame([], $result['unresolved']);

        $candidate['nodes'][0]['api_ids'] = [$this->id('b')];
        $result = $this->preview($candidate);
        self::assertSame([$this->id('b'), $this->id('c')], $result['removed']);
        self::assertContains(['code' => 'group_cannot_grant_api', 'node_key' => 'platform.fixture.group'], $result['unresolved']);
    }

    public function testReadsTrackRevokedGrantsRemovedMembershipAndDeletedApi(): void
    {
        $candidate = $this->candidate();
        self::assertTrue($this->preview($candidate)['relation_coverage_complete']);
        $this->database->exec('DELETE FROM pa_platform_role_permission WHERE platform_role_id=202 AND permission_id=13');
        $result = $this->preview($candidate);
        self::assertTrue($result['capability_equal']);
        self::assertFalse($result['relation_coverage_complete']);
        self::assertSame([$this->id('a')], $result['roles'][1]['added']);

        $this->database->exec('DELETE FROM pa_platform_operator_role WHERE platform_operator_id=22 AND platform_role_id=202');
        $result = $this->preview($candidate);
        self::assertTrue($result['capability_equal']);
        self::assertContains(['code' => 'role_not_assigned_to_operator', 'role_id' => '202'], $result['unresolved']);

        $this->database->prepare('DELETE FROM pa_system_api WHERE id=?')->execute([$this->id('b')]);
        $result = $this->preview($candidate);
        self::assertTrue($result['capability_equal']);
        self::assertFalse($result['relation_coverage_complete']);
        self::assertContains([
            'code' => 'legacy_api_not_active_platform', 'permission_key' => 'platform.fixture.read', 'api_id' => $this->id('b'),
        ], $result['unresolved']);
    }

    public function testInactiveDanglingAndForeignRoleGrantsStayVisibleAsUnresolved(): void
    {
        $candidate = $this->candidate();
        $this->database->exec("UPDATE pa_platform_role SET status='archived' WHERE id=202");
        $this->database->exec('INSERT INTO pa_platform_role_permission VALUES (201,21),(201,999)');
        $result = $this->preview($candidate);
        self::assertContains(['code' => 'role_inactive', 'role_id' => '202'], $result['unresolved']);
        self::assertContains(['code' => 'legacy_role_grant_not_active_platform', 'role_id' => '201'], $result['unresolved']);
        self::assertSame([], $result['added']);

        $this->database->exec('DELETE FROM pa_platform_role WHERE id=202');
        $result = $this->preview($candidate);
        self::assertContains(['code' => 'role_missing', 'role_id' => '202'], $result['unresolved']);

        $this->database->exec("UPDATE pa_platform_operator SET status='suspended' WHERE id=22");
        $result = $this->preview($candidate);
        self::assertTrue($result['capability_equal']);
        self::assertFalse($result['relation_coverage_complete']);
        self::assertContains(['code' => 'operator_inactive'], $result['unresolved']);
        self::assertSame(['201', '202'], array_column($result['roles'], 'role_id'));
    }

    public function testOwnerDynamicGrantsAreRetainedAndAnOperatorWithSimilarRoleDoesNotGetThem(): void
    {
        $candidate = ['operator_id' => '33', 'nodes' => [], 'role_assignments' => [['role_id' => '301', 'node_keys' => []]]];
        $result = $this->preview($candidate);
        self::assertTrue($result['owner_dynamic']);
        self::assertTrue($result['capability_equal']);
        self::assertTrue($result['relation_coverage_complete']);
        self::assertTrue($this->roles->permissions(33)->allows('platform.role.read'));

        $materializedOwner = $candidate;
        $materializedOwner['nodes'][] = ['key' => 'platform.fixture.owner', 'parent_key' => null, 'type' => 'operation', 'api_ids' => [$this->id('1')]];
        $materializedOwner['role_assignments'][0]['node_keys'][] = 'platform.fixture.owner';
        $result = $this->preview($materializedOwner);
        self::assertTrue($result['capability_equal']);
        self::assertFalse($result['relation_coverage_complete']);
        self::assertSame([$this->id('1')], $result['roles'][0]['added']);

        $this->database->exec("UPDATE pa_platform_role SET is_builtin=0 WHERE id=301");
        $result = $this->preview($candidate);
        self::assertFalse($result['owner_dynamic']);
        self::assertTrue($result['relation_coverage_complete']);
        self::assertFalse($this->roles->permissions(33)->allows('platform.role.read'));
        self::assertFalse($this->roles->permissions(44)->allows('platform.role.read'));
    }

    public function testPrerequisiteUnionAndTargetDemoAndModuleConstraintsDoNotRewriteRelations(): void
    {
        $candidate = $this->candidate();
        $candidate['role_assignments'][0]['node_keys'] = ['platform.fixture.page'];
        $result = $this->preview($candidate);
        self::assertSame([$this->id('b'), $this->id('c')], $result['removed']);
        self::assertSame($result['removed'], $result['roles'][0]['removed']);

        $unavailable = $this->createStub(ModuleAvailability::class);
        $unavailable->method('assertDeployment')->willThrowException(new ModuleException('MODULE_NOT_INSTALLED', 'Synthetic optional module'));
        $demo = new DemoAccountPolicy(true, ['demo-target@example.test']);
        $result = $this->preview($candidate, $unavailable, $demo);
        self::assertTrue($result['capability_equal']);
        self::assertFalse($result['relation_coverage_complete']);
        self::assertSame([$this->id('b'), $this->id('c')], $result['roles'][0]['removed']);

        $this->database->exec('DELETE FROM pa_platform_role_permission WHERE platform_role_id=202 AND permission_id=12');
        $result = $this->preview($candidate);
        self::assertSame([$this->id('c')], $result['removed']);
        self::assertSame([$this->id('b'), $this->id('c')], $result['roles'][0]['removed']);
        self::assertNotContains('platform.fixture.prerequisite', $result['non_http_permission_keys']);
    }


    #[DataProvider('legacyProjectionCases')]
    public function testEmptyCandidateRemovesExactlyWhatNativeLegacyProjectionAllows(
        bool $hasPrerequisite,
        bool $moduleAvailable,
        bool $demoLocked,
        array $expectedIds,
    ): void {
        if (!$hasPrerequisite) {
            $this->database->exec('DELETE FROM pa_platform_role_permission WHERE platform_role_id=202 AND permission_id=12');
        }
        $modules = $this->createStub(ModuleAvailability::class);
        if (!$moduleAvailable) {
            $modules->method('assertDeployment')->willThrowException(new ModuleException('MODULE_NOT_INSTALLED', 'Synthetic optional module'));
        }
        $demo = new DemoAccountPolicy($demoLocked, ['demo-target@example.test']);
        $candidate = [
            'operator_id' => '22',
            'nodes' => [],
            'role_assignments' => [
                ['role_id' => '201', 'node_keys' => []],
                ['role_id' => '202', 'node_keys' => []],
            ],
        ];
        $preview = $this->preview($candidate, $modules, $demo);
        $oldAccess = $this->catalog->platformAccess(
            $this->roles->permissions(22)->keys(),
            $modules,
            $demo->platformMutationLocked(222),
        );
        $removed = [];
        $rows = $this->database->query('SELECT id,method,path FROM pa_system_api ORDER BY path,method');
        foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $api) {
            if (in_array($api['id'], $preview['removed'], true)) {
                $removed[] = ['method' => $api['method'], 'path' => $api['path']];
            }
        }

        self::assertSame($oldAccess, $removed);
        self::assertSame(array_map($this->id(...), $expectedIds), $preview['removed']);
        self::assertSame([], $preview['added']);
    }

    public static function legacyProjectionCases(): array
    {
        return [
            'all constraints pass' => [true, true, false, ['a', 'b', 'c']],
            'prerequisite revoked' => [false, true, false, ['a', 'c']],
            'optional module unavailable' => [true, false, false, ['a', 'b']],
            'target demo is locked' => [true, true, true, ['a', 'c']],
        ];
    }

    public function testEachActualActorPermissionIsMandatoryBeforeCandidateAndTargetReads(): void
    {
        foreach ([1, 2, 3] as $permissionId) {
            $this->database->exec("DELETE FROM pa_platform_role_permission WHERE platform_role_id=101 AND permission_id={$permissionId}");
            $this->database->exec('UPDATE pa_platform_role SET revision=revision+1 WHERE id=101');
            try {
                $this->preview(['operator_id' => '999']);
                self::fail('Actor without one of the required permissions was accepted');
            } catch (AuthorizationException) {
                self::assertTrue(true);
            }
            $this->database->exec("INSERT INTO pa_platform_role_permission VALUES (101,{$permissionId})");
            $this->database->exec('UPDATE pa_platform_role SET revision=revision+1 WHERE id=101');
        }
    }

    private function preview(array $candidate, ?ModuleAvailability $modules = null, ?DemoAccountPolicy $demo = null): array
    {
        $context = PlatformContext::fromValidatedSession(
            new ValidatedPlatformSession(1, 'fixture-platform-session', 111, 11, 'platform-web', new DateTimeImmutable('2031-01-01T00:00:00Z')),
            'platform-migration-preview-fixture',
        );
        return (new PlatformRoleAuthorizationPreview($this->authorization, $this->roles, $this->catalog, $demo ?? new DemoAccountPolicy(false, [])))
            ->preview($context, $candidate, $modules ?? $this->modules);
    }

    private function candidate(): array
    {
        return PlatformRoleAuthorizationPreviewFixture::exactCandidate();
    }

    private function id(string $value): string
    {
        return str_repeat($value, 64);
    }

    private function assertInvalid(array $candidate): void
    {
        try {
            $this->preview($candidate);
            self::fail('Invalid preview input was accepted');
        } catch (AdminAccessException $exception) {
            self::assertSame('ROLE_AUTHORIZATION_PREVIEW_INVALID', $exception->errorCode);
        }
    }
}
