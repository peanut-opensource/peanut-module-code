<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Workflow\Tests\Integration\Application;

use app\common\contract\authorization\AdminAuthorizationQuery;
use LogicException;
use PeanutAdmin\DataPermission\Catalog\ResourceOperationCatalog;
use PeanutAdmin\DataPermission\Policy\PolicyRepository;
use PeanutAdmin\Fixtures\WorkflowBusiness\ModuleProvider;
use PeanutAdmin\Fixtures\WorkflowBusiness\Package;
use PeanutAdmin\Kernel\Async\TrustedEnvelopeCodec;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Authorization\Application\AdminAccessException;
use PeanutAdmin\Kernel\Authorization\EffectivePermissionSet;
use PeanutAdmin\Kernel\Authorization\TenantAuthorizationRepository;
use PeanutAdmin\Kernel\Context\AuthorizedOperationContext;
use PeanutAdmin\Kernel\Module\CompiledModuleRegistry;
use PeanutAdmin\Kernel\Module\ModuleAvailability;
use PeanutAdmin\Kernel\Module\StrictVersionConstraintMatcher;
use PeanutAdmin\Modules\ArtifactRevision\Contract\ArtifactRevisionQuery;
use PeanutAdmin\Modules\Identity\Audit\AuditService;
use PeanutAdmin\Modules\Identity\Contract\Dto\TenantMemberSummary;
use PeanutAdmin\Modules\Identity\Contract\TenantMemberDirectory;
use PeanutAdmin\Modules\Identity\Contract\TenantModuleStateQueries;
use PeanutAdmin\Modules\Identity\Contract\TenantSessionAccess;
use PeanutAdmin\Modules\Identity\Contract\WorkflowOrganizationQuery;
use PeanutAdmin\Modules\Identity\Module\TenantEntitlementService;
use PeanutAdmin\Modules\Notification\Contract\NotificationProducerRegistry;
use PeanutAdmin\Modules\Task\Contract\TrustedJobPublisher;
use PeanutAdmin\Modules\Task\Job\Persistence\TaskJobStore;
use PeanutAdmin\Modules\Task\Job\Submission\TaskSubmissionRegistry;
use PeanutAdmin\Modules\Workflow\Adapter\WorkflowAssignmentResolver;
use PeanutAdmin\Modules\Workflow\Adapter\WorkflowScenarioProvider;
use PeanutAdmin\Modules\Workflow\Application\WorkflowDefinitionValidator;
use PeanutAdmin\Modules\Workflow\Application\WorkflowException;
use PeanutAdmin\Modules\Workflow\Application\WorkflowLifecycleQueries;
use PeanutAdmin\Modules\Workflow\Application\WorkflowQueries;
use PeanutAdmin\Modules\Workflow\Definition\WorkflowCondition;
use PeanutAdmin\Modules\Workflow\Definition\WorkflowGraph;
use PeanutAdmin\Modules\Workflow\Integration\ScenarioWorkflowAuthorizationResolver;
use PeanutAdmin\Modules\Workflow\Integration\WorkflowScenarioRegistry;
use PHPUnit\Framework\TestCase;
use think\App;

require_once __DIR__ . '/WorkflowCapabilityCompositionTest.php';

/**
 * Explicit source-fixture composition, with no application initialization or database.
 * Graph end-node reachability is not a persisted Workflow instance terminal receipt.
 * Run in the Module author host via scripts/run-php-test with
 * PEANUT_WORKFLOW_BUSINESS_FIXTURE_ROOT pointing to the existing Code fixture.
 */
#[\PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses]
#[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
final class WorkflowBusinessFixtureContractTest extends TestCase
{
    private static string $fixture;
    private \think\Container $previousContainer;

    protected function setUp(): void
    {
        $this->previousContainer = \think\Container::getInstance();
    }

    protected function tearDown(): void
    {
        \think\Container::setInstance($this->previousContainer);
    }

    public static function setUpBeforeClass(): void
    {
        $input = getenv('PEANUT_WORKFLOW_BUSINESS_FIXTURE_ROOT');
        $root = is_string($input) && str_starts_with($input, '/') ? realpath($input) : false;
        if ($root === false || !is_file($root . '/module.json') || !is_file($root . '/composer.json')) {
            throw new LogicException('An explicit existing Workflow business fixture root is required.');
        }
        self::$fixture = $root;
        $composer = self::json($root . '/composer.json');
        $loader = require dirname(__DIR__, 6) . '/vendor/autoload.php';
        foreach ($composer['autoload']['psr-4'] as $namespace => $path) {
            $loader->addPsr4($namespace, $root . '/' . $path);
        }
    }

    public function testDatabaseConnectionsAreForbidden(): void
    {
        $this->composition();
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Database access is outside this contract test.');
        \think\facade\Db::connect();
    }

    public function testFixtureDependenciesMatchTheCurrentAuthorHostSources(): void
    {
        $manifest = self::json(self::$fixture . '/module.json');
        self::assertSame($manifest['version'], self::json(self::$fixture . '/composer.json')['version']);
        $matcher = new StrictVersionConstraintMatcher();
        $mismatches = [];
        foreach ([...$manifest['dependencies'], ...$manifest['optional_dependencies']] as $dependency) {
            [$vendor, $module] = explode('.', $dependency['module_key'], 2);
            $source = dirname(__DIR__, 6) . '/app/modules/' . $vendor . '/' . str_replace('-', '_', $module) . '/module.json';
            $installed = self::json($source);
            self::assertSame($dependency['module_key'], $installed['key']);
            if (!$matcher->matches($installed['version'], $dependency['version'])) {
                $mismatches[] = $installed['key'] . ': ' . $installed['version'] . ' does not match ' . $dependency['version'];
            }
        }
        self::assertSame([], $mismatches, implode("\n", $mismatches));
    }

    public function testNativeContributorRegistersAllThreeScenesAndRejectsReplacement(): void
    {
        [$app, $registry, $publisher, $producers] = $this->composition();
        self::assertCount(3, $registry->registrations());
        foreach (Package::SCENES as $scene) {
            $metadata = $registry->scenario(self::context($scene), Package::MODULE_KEY, $scene);
            $action = $metadata['actions'][Package::action($scene)];
            $publisher->assertRegistered($action['task_type'], Package::resource($scene), $action['operation']);
            $producer = $producers->require($metadata['notification']['producer_key']);
            self::assertSame(Package::resource($scene), $producer->resourceKey());
            $publisher->assertRegistered($producer->taskType(), $producer->resourceKey(), $producer->operation());
        }
        try {
            (new ModuleProvider($app))->registerWorkflowScenarios($registry, $app);
            self::fail('The selected fixture must not replace an existing scenario owner.');
        } catch (LogicException $exception) {
            self::assertStringContainsString('REGISTRATION_CONFLICT', $exception->getMessage());
            self::assertCount(3, $registry->registrations());
        }
    }

    public function testOriginalGraphsValidateAndTheirPositivePathsReachDeclaredEndNodes(): void
    {
        [, $registry, $publisher, $producers, $availability] = $this->composition();
        $validator = new WorkflowDefinitionValidator($registry, $publisher, $availability, $producers);
        foreach (Package::SCENES as $scene) {
            $document = self::json(self::$fixture . '/resources/workflows/' . $scene . '.json');
            $graph = WorkflowGraph::fromArray($document['graph']);
            self::assertSame($document['graph_sha256'], $graph->sha256);
            $validator->validate(self::context($scene), Package::MODULE_KEY, $scene, $graph);
            $metadata = $registry->scenario(self::context($scene), Package::MODULE_KEY, $scene);
            // These are graph contract checks. No start, decision, worker or DB write is invoked.
            $node = $graph->startNode();
            $visited = [];
            while ($node['type'] !== 'end') {
                self::assertLessThan(12, count($visited), 'The chosen positive path must terminate.');
                $visited[] = $node['key'];
                if ($node['type'] === 'exclusive_gateway') {
                    $transition = $node['config']['default_transition_key'];
                    foreach ($node['config']['branches'] as $branch) {
                        if (WorkflowCondition::evaluate($branch['condition'], ['amount_minor' => 120000, 'result' => 'completed'], $metadata['fields'])) {
                            $transition = $branch['transition_key'];
                            break;
                        }
                    }
                    $edges = array_values(array_filter($graph->outgoing($node['key']), static fn(array $edge): bool => $edge['key'] === $transition));
                    self::assertCount(1, $edges);
                    $edge = $edges[0];
                } else {
                    $action = match ($node['type']) {
                        'start' => 'advance',
                        'approval' => 'approve',
                        'human_task' => $node['key'] === 'correction' ? 'resubmit' : 'complete',
                        'service_task' => 'succeeded',
                        default => throw new LogicException('Unexpected fixture node.'),
                    };
                    $edge = $graph->edge($node['key'], $action);
                }
                $node = $graph->node($edge['to']);
            }
            self::assertSame($scene === 'receipt' ? 'completed' : 'approved', $node['config']['result_code']);
            self::assertContains($scene === 'receipt' ? 'effect-completed' : 'effect-approved', $visited);
        }
    }

    public function testCurrentPermissionAndTenantMembershipAreCheckedBeforeScenarioMetadata(): void
    {
        [, $registry] = $this->composition(readAllowed: false);
        try {
            $registry->scenario(self::context('application'), Package::MODULE_KEY, 'application');
            self::fail('Revoked fixture read permission must fail closed.');
        } catch (AdminAccessException $exception) {
            self::assertSame('WORKFLOW_BUSINESS_ACCESS_DENIED', $exception->errorCode);
        }
        [, $registry] = $this->composition();
        try {
            $registry->scenario(self::context('application', 202), Package::MODULE_KEY, 'application');
            self::fail('A synthetic member from another tenant must not be accepted.');
        } catch (AdminAccessException $exception) {
            self::assertSame('WORKFLOW_BUSINESS_ACCESS_DENIED', $exception->errorCode);
        }
        WorkflowCapabilityCompositionTest::assertWorkflowFailure(
            'WORKFLOW_DEFINITION_INVALID',
            fn() => $registry->scenario(self::context('application'), Package::MODULE_KEY, 'unregistered'),
        );
    }

    public function testPreparedSnapshotsRejectForeignTenantBeforeStorageAccess(): void
    {
        [, $registry] = $this->composition();
        $resource = Package::resource('application');
        $object = 'object_' . str_repeat('a', 32);
        $revision = 'revision_' . str_repeat('b', 32);
        $prepared = new \PeanutAdmin\Fixtures\WorkflowBusiness\Integration\PreparedWorkflowBusinessSnapshot(
            101,
            $resource,
            $object,
            $revision,
            hash('sha256', 'synthetic immutable snapshot'),
        );
        WorkflowCapabilityCompositionTest::assertWorkflowFailure(
            'WORKFLOW_SUBJECT_REVISION_CONFLICT',
            fn() => $registry->snapshot(self::context('application', 202), $resource, $object, $revision, true, $prepared),
        );
    }

    public function testOriginalServiceTaskGraphsRejectAnAbsentTaskPublisher(): void
    {
        [, $registry, , $producers, $availability] = $this->composition();
        $graph = WorkflowGraph::fromArray(self::json(self::$fixture . '/resources/workflows/application.json')['graph']);
        $validator = new WorkflowDefinitionValidator($registry, null, $availability, $producers);
        WorkflowCapabilityCompositionTest::assertWorkflowFailure(
            'CAPABILITY_UNSUPPORTED',
            fn() => $validator->validate(self::context('application'), Package::MODULE_KEY, 'application', $graph),
        );
    }

    /**
     * Native concrete contributor, owner service, validators and task registries are used.
     * Only external identity/permission/availability query contracts are synthetic.
     * No credentials, real sessions, object rows, effects or module installations are created.
     * @return array{App, WorkflowScenarioRegistry, TrustedJobPublisher, NotificationProducerRegistry, ModuleAvailability}
     */
    private function composition(bool $readAllowed = true): array
    {
        $app = new App(dirname(__DIR__, 6));
        $app->bind('think\\DbManager', static fn() => throw new LogicException('Database access is outside this contract test.'));
        \think\Container::setInstance($app);
        $permissions = $this->createStub(TenantAuthorizationRepository::class);
        $permissions->method('permissionsForKeys')->willReturnCallback(
            static fn(TenantContext $context, array $keys): EffectivePermissionSet => new EffectivePermissionSet(
                $readAllowed && $context->tenantId === 101 && $context->memberId === 11
                    ? array_values(array_intersect($keys, [Package::MODULE_KEY . '.read'])) : [],
            ),
        );
        $members = $this->createStub(TenantMemberDirectory::class);
        $members->method('current')->willReturnCallback(
            static fn(TenantContext $context): ?TenantMemberSummary => $context->tenantId === 101 && $context->memberId === 11
                ? new TenantMemberSummary(101, 11, 111, 'Synthetic fixture member', 1, 1, 1, 1, 1) : null,
        );
        $availability = $this->createStub(ModuleAvailability::class);
        $operations = $this->createStub(ResourceOperationCatalog::class);
        foreach ([
            TenantAuthorizationRepository::class => $permissions,
            TenantMemberDirectory::class => $members,
            ModuleAvailability::class => $availability,
            ResourceOperationCatalog::class => $operations,
            PolicyRepository::class => $this->createStub(PolicyRepository::class),
            WorkflowOrganizationQuery::class => $this->createStub(WorkflowOrganizationQuery::class),
            ArtifactRevisionQuery::class => $this->createStub(ArtifactRevisionQuery::class),
            TenantSessionAccess::class => $this->createStub(TenantSessionAccess::class),
            AdminAuthorizationQuery::class => $this->createStub(AdminAuthorizationQuery::class),
            AuditService::class => new AuditService(),
        ] as $contract => $instance) {
            $app->instance($contract, $instance);
        }
        $registry = new WorkflowScenarioRegistry();
        $app->instance(WorkflowScenarioProvider::class, $registry);
        $app->instance(WorkflowScenarioRegistry::class, $registry);
        $app->instance(WorkflowQueries::class, new WorkflowQueries(
            new ScenarioWorkflowAuthorizationResolver($registry),
            $this->createStub(WorkflowAssignmentResolver::class),
            new WorkflowLifecycleQueries(new TenantModuleStateQueries(), $availability),
            $registry,
        ));
        $producers = new NotificationProducerRegistry();
        $app->instance(NotificationProducerRegistry::class, $producers);
        $provider = new ModuleProvider($app);
        $provider->registerWorkflowScenarios($registry, $app);
        foreach ($provider->notificationProducers($app) as $producer) {
            $producers->register($producer);
        }
        $publisher = new TrustedJobPublisher(
            new TaskJobStore(),
            new TaskSubmissionRegistry($provider->workflowTaskSubmissions($app)),
            new TrustedEnvelopeCodec(hash('sha256', 'public synthetic contract test only')),
            $operations,
            new TenantEntitlementService(new CompiledModuleRegistry([], [], [], [], 'synthetic-workflow-fixture')),
        );
        return [$app, $registry, $publisher, $producers, $availability];
    }

    private static function context(string $scene, int $tenant = 101): AuthorizedOperationContext
    {
        return WorkflowCapabilityCompositionTest::context(Package::resource($scene), 'read', $tenant, 11);
    }

    private static function json(string $path): array
    {
        return json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    }
}
