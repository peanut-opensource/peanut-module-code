<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Workflow\Tests\Integration\Application;

use DateTimeImmutable;
use PDO;
use PeanutAdmin\Kernel\Context\AuthorizedOperationContext;
use PeanutAdmin\Kernel\Context\AuthorizationDecision;
use PeanutAdmin\Kernel\Context\RequestedTargetSet;
use PeanutAdmin\Kernel\Idempotency\IdempotencyService;
use PeanutAdmin\Kernel\Module\CompiledModuleRegistry;
use PeanutAdmin\Kernel\Module\ModuleAvailability;
use PeanutAdmin\Modules\Identity\Audit\AuditService;
use PeanutAdmin\Modules\Identity\Contract\TenantModuleStateQueries;
use PeanutAdmin\Modules\Identity\Module\TenantEntitlementService;
use PeanutAdmin\Modules\Workflow\Adapter\WorkflowAssignmentResolver;
use PeanutAdmin\Modules\Workflow\Adapter\WorkflowAttachmentResolver;
use PeanutAdmin\Modules\Workflow\Adapter\WorkflowAuthorizationResolver;
use PeanutAdmin\Modules\Workflow\Adapter\WorkflowScenarioProvider;
use PeanutAdmin\Modules\Workflow\Application\WorkflowCommands;
use PeanutAdmin\Modules\Workflow\Application\WorkflowCommandTransaction;
use PeanutAdmin\Modules\Workflow\Application\WorkflowDefinitionService;
use PeanutAdmin\Modules\Workflow\Application\WorkflowDefinitionValidator;
use PeanutAdmin\Modules\Workflow\Application\WorkflowException;
use PeanutAdmin\Modules\Workflow\Application\WorkflowLifecycleQueries;
use PeanutAdmin\Modules\Workflow\Application\WorkflowQueries;
use PeanutAdmin\Modules\Workflow\Tests\Unit\Definition\WorkflowGraphTest;
use PHPUnit\Framework\TestCase;
use think\Container;
use think\facade\Db;
use Throwable;

require_once __DIR__ . '/WorkflowCapabilityCompositionTest.php';
require_once dirname(__DIR__, 2) . '/Unit/Definition/WorkflowGraphTest.php';
require_once dirname(__DIR__, 5) . '/Support/RegisteredMysqlTestResource.php';
require_once dirname(__DIR__, 5) . '/Support/ThinkPhpTestConnection.php';
require_once dirname(__DIR__, 5) . '/Support/IdentityMysqlFixture.php';

/** Native MySQL fixtures are isolated by the existing registry resource guard. */
abstract class WorkflowMysqlTestCase extends TestCase
{
    protected PDO $pdo;
    private string $database;
    private bool $created;
    protected WorkflowLifecycleQueries $lifecycle;
    protected ModuleAvailability $availability;
    protected WorkflowScenarioProvider $scenarios;
    protected WorkflowAssignmentResolver $assignments;
    protected WorkflowAuthorizationResolver $authorization;
    protected WorkflowDefinitionService $definitions;
    protected WorkflowCommands $commands;
    protected WorkflowQueries $queries;
    protected WorkflowCommandTransaction $transactions;
    protected bool $denied = false;
    protected array $members = [12];
    protected array $scenarioMetadata;
    protected array $additionalStateTables = [];

    protected function setUp(): void
    {
        if (getenv('PEANUT_INTEGRATION') !== '1') {
            self::markTestSkipped('Requires the registered, isolated MySQL test resource; never uses the development database.');
        }
        $this->database = \RegisteredMysqlTestResource::configuredDatabaseName();
        [$this->pdo, $this->created] = \RegisteredMysqlTestResource::openEmptyDatabase($this->database);
        \ThinkPhpTestConnection::fromPdo($this->pdo);
        $this->install();
        foreach ([1, 2] as $tenant) {
            $this->pdo->exec("INSERT INTO pa_tenant (id,code,name,display_name,status,created_at,updated_at) VALUES ($tenant,'workflow-$tenant','Workflow','Workflow','active',UTC_TIMESTAMP(3),UTC_TIMESTAMP(3))");
        }
        foreach ([11, 12, 13] as $member) {
            $account = 100 + $member;
            $this->pdo->exec("INSERT INTO pa_account (id,display_name,created_at,updated_at) VALUES ($account,'Workflow member',UTC_TIMESTAMP(3),UTC_TIMESTAMP(3))");
            $this->pdo->exec("INSERT INTO pa_tenant_member (id,tenant_id,account_id,status,created_at,updated_at) VALUES ($member,1,$account,'active',UTC_TIMESTAMP(3),UTC_TIMESTAMP(3))");
        }
        $this->pdo->exec("INSERT INTO pa_plan (id,code,name,created_at,updated_at) VALUES (1,'workflow','Workflow',UTC_TIMESTAMP(3),UTC_TIMESTAMP(3))");
        $this->pdo->exec("INSERT INTO pa_plan_module VALUES (1,'peanut.workflow')");
        $this->pdo->exec("INSERT INTO pa_tenant_license (tenant_id,plan_id,effective_at,created_at,updated_at) VALUES (1,1,'2020-01-01',UTC_TIMESTAMP(3),UTC_TIMESTAMP(3)),(2,1,'2020-01-01',UTC_TIMESTAMP(3),UTC_TIMESTAMP(3))");
        Container::getInstance()->instance(TenantEntitlementService::class, new TenantEntitlementService(new CompiledModuleRegistry([], [], [], [], 'workflow-fixture')));
        $this->availability = $this->createStub(ModuleAvailability::class);
        $this->lifecycle = new WorkflowLifecycleQueries(new TenantModuleStateQueries(), $this->availability);
        $this->scenarios = $this->createStub(WorkflowScenarioProvider::class);
        $this->scenarioMetadata = WorkflowCapabilityCompositionTest::metadata();
        $this->scenarios->method('scenario')->willReturnCallback(fn() => $this->scenarioMetadata);
        $this->scenarios->method('prepareSubjectSnapshot')->willReturn(new \stdClass());
        $this->scenarios->method('snapshot')->willReturnCallback(static fn($c, $type, $subject, $revision) => ['revision_key' => $revision, 'sha256' => hash('sha256', $revision), 'fields' => [], 'reference' => []]);
        $this->scenarios->method('validateCompletion')->willReturn([]);
        $this->assignments = $this->createStub(WorkflowAssignmentResolver::class);
        $this->assignments->method('resolve')->willReturnCallback(fn($c, $rule, $basis) => array_map(static fn($member) => ['member_id' => $member, 'source_kind' => $rule['kind'], 'source_key' => $rule['key'], 'binding_revision' => '1'], $this->members));
        $this->assignments->method('eligible')->willReturn(true);
        $this->authorization = $this->createStub(WorkflowAuthorizationResolver::class);
        $this->authorization->method('authorize')->willReturnCallback(function ($basis, $resource, $operation, $permissions, $subject) {
            if ($this->denied) { throw WorkflowException::assignmentDenied(); }
            return AuthorizedOperationContext::fromDecision(AuthorizationDecision::allow($basis->tenantContext, $resource, $operation, [new RequestedTargetSet($resource, [$subject])], hash('sha256', implode('|', $permissions))));
        });
        $this->transactions = new WorkflowCommandTransaction(new IdempotencyService(), $this->lifecycle);
        $this->queries = new WorkflowQueries($this->authorization, $this->assignments, $this->lifecycle, $this->scenarios);
        $this->definitions = new WorkflowDefinitionService($this->transactions, $this->scenarios, $this->assignments, new AuditService(), $this->lifecycle, new WorkflowDefinitionValidator($this->scenarios, null, $this->availability), $this->authorization);
        $this->commands = $this->makeCommands();
    }

    protected function install(): void
    {
        \IdentityMysqlFixture::install($this->pdo, [dirname(__DIR__, 6) . '/app/modules/peanut/workflow/database/migrations']);
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo, $this->created)) {
            \RegisteredMysqlTestResource::cleanup($this->pdo, $this->database, $this->created);
        }
    }

    protected function makeCommands(?WorkflowAttachmentResolver $attachments = null): WorkflowCommands
    {
        return new WorkflowCommands($this->transactions, $this->authorization, $this->assignments, $this->scenarios, $this->queries, new AuditService(), $this->lifecycle, $attachments);
    }

    protected function context(string $operation = 'read', int $tenant = 1, int $member = 11): AuthorizedOperationContext
    {
        return WorkflowCapabilityCompositionTest::context('peanut.workflow.definition', $operation, $tenant, $member);
    }

    protected function publish(?array $graph = null): array
    {
        $draft = $this->definitions->saveDraft($this->context('write'), 'module.sample', 'approval', ['expected_revision' => null, 'graph' => $graph ?? WorkflowGraphTest::validGraph(true), 'layout' => ['nodes' => [], 'viewport' => ['x' => 0, 'y' => 0, 'zoom' => 1]]], 'workflow-draft');
        return $this->definitions->publish($this->context('publish'), 'module.sample', 'approval', ['expected_revision' => $draft['draft_revision']], 'workflow-publish');
    }

    protected function start(string $subject = 'subject-1', string $key = 'workflow-start', array $files = []): array
    {
        return $this->commands->start($this->context(), ['module_key' => 'module.sample', 'workflow_key' => 'approval', 'subject_type' => 'subject.item', 'subject_key' => $subject, 'subject_revision_key' => 'revision-1', 'expected_revision' => 2, 'business_department_key' => null, 'attachment_file_keys' => $files], $key);
    }

    protected function request(array $instance, string $action = 'approve', int $member = 12): array
    {
        $item = Db::name('workflow_work_item')->where('instance_id', Db::name('workflow_instance')->where('instance_key', $instance['instance_key'])->value('id'))->where('status', 'pending')->where('assignee_member_id', $member)->order('id', 'desc')->find();
        $node = Db::name('workflow_node_execution')->where('id', $item['node_execution_id'])->find();
        $request = ['node_execution_key' => $node['node_execution_key'], 'expected_revision' => $instance['revision'], 'expected_node_revision' => (int) $node['revision'], 'expected_task_revision' => (int) $item['revision'], 'comment' => null, 'attachment_file_keys' => []];
        if (in_array($action, ['approve', 'reject'], true)) { $request['decision'] = $action; }
        if ($action === 'complete') { $request['input'] = []; }
        return [$item['work_item_key'], $request];
    }

    protected function state(): array
    {
        $state = [];
        foreach ([...['workflow_definition', 'workflow_definition_version', 'workflow_instance', 'workflow_node_execution', 'workflow_work_item', 'workflow_task_identity_link', 'workflow_event', 'tenant_audit_event', 'tenant_idempotency_record'], ...$this->additionalStateTables] as $table) {
            $state[$table] = $this->pdo->query('SELECT * FROM pa_' . $table . ' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        }
        return $state;
    }

    protected function assertRollback(string $table, string $mutation, callable $command): void
    {
        $before = $this->state();
        $this->pdo->exec("CREATE TRIGGER workflow_test_failure BEFORE $mutation ON pa_$table FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='workflow injected write failure'");
        try {
            $command();
            self::fail('An injected write failure must abort the whole transaction.');
        } catch (\think\db\exception\PDOException $exception) {
            self::assertStringContainsString('workflow injected write failure', $exception->getMessage());
        } finally {
            $this->pdo->exec('DROP TRIGGER workflow_test_failure');
        }
        self::assertSame($before, $this->state(), 'A failed command leaked persisted state.');
        self::assertFalse($this->pdo->inTransaction());
    }
}

final class WorkflowRuntimeTest extends WorkflowMysqlTestCase
{
    public function testDefinitionInstanceHumanCommandsAreAtomicAndReplaySafe(): void
    {
        $published = $this->publish();
        self::assertSame(1, $published['active_version']);
        $instance = $this->start();
        self::assertSame('running', $instance['status']);
        self::assertSame('review', $instance['active_nodes'][0]['node_key']);
        $state = $this->state();
        self::assertSame($instance, $this->start());
        self::assertSame($state, $this->state());
        [$item, $request] = $this->request($instance);
        $approved = $this->commands->decide($this->context(member: 12), $item, $request, 'workflow-approve');
        self::assertSame('action', $approved['active_nodes'][0]['node_key']);
        $state = $this->state();
        self::assertSame($approved, $this->commands->decide($this->context(member: 12), $item, $request, 'workflow-approve'));
        self::assertSame($state, $this->state());
        [$item, $request] = $this->request($approved, 'complete');
        $completed = $this->commands->completeTask($this->context(member: 12), $item, $request, 'workflow-complete');
        self::assertSame('completed', $completed['status']);
        self::assertSame('done', $completed['result_code']);
        self::assertSame([], $completed['active_nodes']);
        self::assertSame(3, count($this->queries->events($this->context(), $instance['instance_key'])));
    }

    public function testStaleRevisionCrossTenantAndChangedReplayFailClosed(): void
    {
        $this->publish();
        $instance = $this->start();
        $before = $this->state();
        WorkflowCapabilityCompositionTest::assertWorkflowFailure('WORKFLOW_SUBJECT_NOT_FOUND', fn() => $this->queries->instance($this->context(tenant: 2), $instance['instance_key']));
        [$item, $request] = $this->request($instance);
        $request['expected_revision'] = 999;
        WorkflowCapabilityCompositionTest::assertWorkflowFailure('WORKFLOW_INSTANCE_CONFLICT', fn() => $this->commands->decide($this->context(member: 12), $item, $request, 'workflow-stale'));
        self::assertSame($before, $this->state());
        try {
            $this->commands->start($this->context(), ['module_key' => 'module.sample', 'workflow_key' => 'approval', 'subject_type' => 'subject.item', 'subject_key' => 'subject-1', 'subject_revision_key' => 'revision-2', 'expected_revision' => 2, 'business_department_key' => null, 'attachment_file_keys' => []], 'workflow-start');
            self::fail('A changed request cannot reuse a completed key.');
        } catch (\PeanutAdmin\Kernel\Api\ApiException $exception) {
            self::assertSame('IDEMPOTENCY_KEY_REUSED', $exception->errorCode);
        }
        self::assertSame($before, $this->state());
    }

    public function testReplayRechecksCurrentAuthorizationAndInvisibleQueriesUseNotFound(): void
    {
        $this->publish();
        $instance = $this->start();
        $before = $this->state();
        $this->denied = true;
        WorkflowCapabilityCompositionTest::assertWorkflowFailure('WORKFLOW_ASSIGNMENT_DENIED', fn() => $this->start());
        WorkflowCapabilityCompositionTest::assertWorkflowFailure('WORKFLOW_SUBJECT_NOT_FOUND', fn() => $this->queries->instance($this->context(), $instance['instance_key']));
        self::assertSame($before, $this->state());
    }

    public function testAllReviewRequiresEveryFrozenAssignee(): void
    {
        $graph = WorkflowGraphTest::validGraph(true);
        $graph['required_capabilities'][] = 'workflow.all_approve';
        $graph['nodes'][1]['config']['aggregation_policy'] = 'all_approve';
        $this->members = [12, 13];
        $this->publish($graph);
        $instance = $this->start();
        [$item, $request] = $this->request($instance);
        $first = $this->commands->decide($this->context(member: 12), $item, $request, 'workflow-review-first');
        self::assertSame('review', $first['active_nodes'][0]['node_key']);
        $this->members = [12];
        [$item, $request] = $this->request($first, member: 13);
        $second = $this->commands->decide($this->context(member: 13), $item, $request, 'workflow-review-second');
        self::assertSame('action', $second['active_nodes'][0]['node_key']);
        self::assertSame(2, (int) Db::name('workflow_work_item')->where('node_key', 'review')->where('status', 'completed')->count());
    }

    public function testWithdrawalCancelsPendingItemsAndKeepsHistory(): void
    {
        $this->publish();
        $instance = $this->start();
        $withdrawn = $this->commands->withdraw($this->context(), $instance['instance_key'], ['expected_revision' => $instance['revision'], 'comment' => 'Withdraw this submission'], 'workflow-withdraw');
        self::assertSame('cancelled', $withdrawn['status']);
        self::assertSame(0, (int) Db::name('workflow_work_item')->where('status', 'pending')->count());
        self::assertSame(1, (int) Db::name('workflow_work_item')->where('status', 'cancelled')->count());
        self::assertCount(2, $this->queries->events($this->context(), $instance['instance_key']));
    }

    public function testRuntimeRollsBackAtEveryDatabaseWriteCheckpointAndRecovers(): void
    {
        $this->publish();
        foreach (['workflow_instance', 'workflow_node_execution', 'workflow_work_item', 'workflow_event', 'tenant_audit_event'] as $table) {
            $this->assertRollback($table, 'INSERT', fn() => $this->start());
        }
        $this->assertRollback('tenant_idempotency_record', 'UPDATE', fn() => $this->start());
        $instance = $this->start();
        self::assertSame('running', $instance['status']);
        [$item, $request] = $this->request($instance);
        foreach ([['workflow_instance', 'UPDATE'], ['workflow_node_execution', 'UPDATE'], ['workflow_node_execution', 'INSERT'], ['workflow_work_item', 'UPDATE'], ['workflow_work_item', 'INSERT'], ['workflow_event', 'INSERT'], ['tenant_audit_event', 'INSERT'], ['tenant_idempotency_record', 'UPDATE']] as [$table, $mutation]) {
            $this->assertRollback($table, $mutation, fn() => $this->commands->decide($this->context(member: 12), $item, $request, 'workflow-approve'));
        }
        self::assertSame('action', $this->commands->decide($this->context(member: 12), $item, $request, 'workflow-approve')['active_nodes'][0]['node_key']);
    }

    public function testDefinitionAndTerminalCommandsRollbackAtEveryWriteCheckpoint(): void
    {
        foreach (['workflow_definition', 'tenant_audit_event'] as $table) {
            $this->assertRollback($table, 'INSERT', fn() => $this->publish());
        }
        $draft = $this->definitions->saveDraft($this->context('write'), 'module.sample', 'approval', ['expected_revision' => null, 'graph' => WorkflowGraphTest::validGraph(true), 'layout' => ['nodes' => [], 'viewport' => ['x' => 0, 'y' => 0, 'zoom' => 1]]], 'workflow-draft');
        foreach ([['workflow_definition_version', 'INSERT'], ['workflow_definition', 'UPDATE'], ['tenant_audit_event', 'INSERT'], ['tenant_idempotency_record', 'UPDATE']] as [$table, $mutation]) {
            $this->assertRollback($table, $mutation, fn() => $this->definitions->publish($this->context('publish'), 'module.sample', 'approval', ['expected_revision' => $draft['draft_revision']], 'workflow-publish'));
        }
        $this->definitions->publish($this->context('publish'), 'module.sample', 'approval', ['expected_revision' => $draft['draft_revision']], 'workflow-publish');
        $instance = $this->start();
        foreach ([['workflow_instance', 'UPDATE'], ['workflow_node_execution', 'UPDATE'], ['workflow_work_item', 'UPDATE'], ['workflow_event', 'INSERT'], ['tenant_audit_event', 'INSERT'], ['tenant_idempotency_record', 'UPDATE']] as [$table, $mutation]) {
            $this->assertRollback($table, $mutation, fn() => $this->commands->withdraw($this->context(), $instance['instance_key'], ['expected_revision' => $instance['revision'], 'comment' => 'Withdraw submission'], 'workflow-withdraw'));
        }
        self::assertSame('cancelled', $this->commands->withdraw($this->context(), $instance['instance_key'], ['expected_revision' => $instance['revision'], 'comment' => 'Withdraw submission'], 'workflow-withdraw')['status']);
    }

    public function testCompletionAndRetirementRollbackAtEveryWriteCheckpoint(): void
    {
        $this->publish();
        $instance = $this->start();
        [$item, $request] = $this->request($instance);
        $instance = $this->commands->decide($this->context(member: 12), $item, $request, 'workflow-approve');
        [$item, $request] = $this->request($instance, 'complete');
        foreach ([['workflow_instance', 'UPDATE'], ['workflow_node_execution', 'UPDATE'], ['workflow_work_item', 'UPDATE'], ['workflow_event', 'INSERT'], ['tenant_audit_event', 'INSERT'], ['tenant_idempotency_record', 'UPDATE']] as [$table, $mutation]) {
            $this->assertRollback($table, $mutation, fn() => $this->commands->completeTask($this->context(member: 12), $item, $request, 'workflow-complete'));
        }
        self::assertSame('completed', $this->commands->completeTask($this->context(member: 12), $item, $request, 'workflow-complete')['status']);
        foreach ([['workflow_definition', 'UPDATE'], ['tenant_audit_event', 'INSERT'], ['tenant_idempotency_record', 'UPDATE']] as [$table, $mutation]) {
            $this->assertRollback($table, $mutation, fn() => $this->definitions->retire($this->context('publish'), 'module.sample', 'approval', ['expected_revision' => 2], 'workflow-retire'));
        }
        self::assertSame('retired', $this->definitions->retire($this->context('publish'), 'module.sample', 'approval', ['expected_revision' => 2], 'workflow-retire')['status']);
        WorkflowCapabilityCompositionTest::assertWorkflowFailure('WORKFLOW_SUBJECT_NOT_FOUND', fn() => $this->start('subject-after-retirement', 'workflow-retired-start'));
    }

    public function testRejectsUndeclaredAssignmentSourceBeforeInstanceWrite(): void
    {
        $this->publish();
        $before = $this->state();
        $this->assignments = $this->createStub(WorkflowAssignmentResolver::class);
        $this->assignments->method('resolve')->willReturn([['member_id' => 12, 'source_kind' => 'member', 'source_key' => '12', 'binding_revision' => '1']]);
        $this->assignments->method('eligible')->willReturn(true);
        $this->commands = $this->makeCommands();
        WorkflowCapabilityCompositionTest::assertWorkflowFailure('WORKFLOW_PROVIDER_UNAVAILABLE', fn() => $this->start());
        self::assertSame($before, $this->state());
    }

    public function testDeclaredReturnTraversalStopsAtItsPersistedBound(): void
    {
        $graph = WorkflowGraphTest::validGraph(true);
        $graph['required_capabilities'][] = 'workflow.return';
        $graph['transitions'][] = ['key' => 'return-to-review', 'from' => 'action', 'to' => 'review', 'action' => 'return', 'operation' => 'return', 'permission_keys' => ['subject.item.return'], 'return_edge' => true, 'max_traversals' => 1];
        $this->publish($graph);
        $instance = $this->start();
        [$item, $request] = $this->request($instance);
        $instance = $this->commands->decide($this->context(member: 12), $item, $request, 'workflow-approve-one');
        [$item, $request] = $this->request($instance, 'return');
        $request['comment'] = 'Please review again';
        $instance = $this->commands->returnTask($this->context(member: 12), $item, $request, 'workflow-return-one');
        self::assertSame('review', $instance['active_nodes'][0]['node_key']);
        self::assertSame(2, (int) Db::name('workflow_instance')->value('cycle_no'));
        [$item, $request] = $this->request($instance);
        $instance = $this->commands->decide($this->context(member: 12), $item, $request, 'workflow-approve-two');
        [$item, $request] = $this->request($instance, 'return');
        $request['comment'] = 'Another return must exceed the bound';
        $blocked = $this->commands->returnTask($this->context(member: 12), $item, $request, 'workflow-return-two');
        self::assertSame('blocked', $blocked['status']);
        self::assertSame('Return traversal limit reached.', $blocked['blocker']['message']);
        self::assertSame(2, (int) Db::name('workflow_instance')->value('cycle_no'));
        self::assertSame('pending', Db::name('workflow_work_item')->where('work_item_key', $item)->value('status'));
        self::assertSame(1, (int) Db::name('workflow_event')->where('transition_key', 'return-to-review')->count());
    }

    public function testNativeIdentityPermissionRemovalAndCrossTenantSubjectFailClosed(): void
    {
        $root = dirname(__DIR__, 6) . '/app/modules/peanut/workflow';
        $manifest = \PeanutAdmin\Kernel\Module\ManifestDocument::fromObject($root, json_decode(file_get_contents($root . '/module.json'), flags: JSON_THROW_ON_ERROR));
        $registry = new CompiledModuleRegistry([$manifest], [], [], [], 'workflow-authority-test');
        $this->pdo->exec("INSERT INTO pa_module_installation (module_key,installed_version,manifest_schema_version,manifest_digest,status,created_at,updated_at) VALUES ('peanut.workflow','" . $manifest->data['version'] . "',1,'" . $manifest->digest . "','active',UTC_TIMESTAMP(3),UTC_TIMESTAMP(3))");
        $catalog = new \PeanutAdmin\Modules\Identity\Authorization\Persistence\ThinkPhpAuthorizationCatalogRepository();
        foreach (['read', 'approve'] as $operation) {
            $permission = $catalog->syncPermission(new \PeanutAdmin\Kernel\Authorization\Persistence\PermissionDefinition('subject.item.' . $operation, 'peanut.workflow', 'api', 'Workflow test ' . $operation, 'normal', '1.0.0'));
            $this->pdo->exec("INSERT INTO pa_plan_permission VALUES (1,'subject.item.$operation')");
            if ($operation === 'read') {
                $this->pdo->exec("INSERT INTO pa_role (id,tenant_id,`key`,name,created_at,updated_at) VALUES (1,1,'workflow.reviewer','Reviewer',UTC_TIMESTAMP(3),UTC_TIMESTAMP(3))");
                $this->pdo->exec("INSERT INTO pa_member_role (tenant_id,tenant_member_id,role_id,assigned_at) VALUES (1,11,1,UTC_TIMESTAMP(3))");
            }
            $this->pdo->exec("INSERT INTO pa_role_permission (tenant_id,role_id,permission_id,granted_at) VALUES (1,1,$permission,UTC_TIMESTAMP(3))");
        }
        $identity = new \PeanutAdmin\Modules\Identity\Contract\TenantAuthorizationQuery(new \PeanutAdmin\Modules\Identity\Membership\Query\ThinkPhpTenantMemberDirectory(), $registry, new TenantEntitlementService($registry));
        $this->pdo->exec('CREATE TABLE test_workflow_subject (subject_key VARCHAR(160) PRIMARY KEY, tenant_id BIGINT UNSIGNED NOT NULL) ENGINE=InnoDB');
        $this->pdo->exec("INSERT INTO test_workflow_subject VALUES ('subject-1',1)");
        // A synthetic business owner consumes the current real IAM projection and tenant SQL.
        // It is an explicit fixture, not a production Scenario contributor.
        $access = $this->createStub(\PeanutAdmin\Modules\Workflow\Integration\WorkflowScenarioAccess::class);
        $access->method('authorize')->willReturnCallback(static function ($basis, $resource, $operation, $permissions, $subject) use ($identity) {
            return Db::transaction(static function () use ($basis, $resource, $operation, $permissions, $subject, $identity) {
                $grants = $identity->permissionGrants($basis->tenantContext, $permissions);
                if (array_diff($permissions, array_keys($grants)) !== []) {
                    throw new \PeanutAdmin\Kernel\Api\ApiException('AUTHZ_PERMISSION_DENIED', 403, 'Permission denied.');
                }
                if (Db::table('test_workflow_subject')->where('tenant_id', $basis->tenantContext->tenantId)->where('subject_key', $subject)->value('subject_key') === null) {
                    throw WorkflowException::subjectNotFound();
                }
                return AuthorizedOperationContext::fromDecision(AuthorizationDecision::allow($basis->tenantContext, $resource, $operation, [new RequestedTargetSet($resource, [$subject])], hash('sha256', implode('|', $permissions))));
            });
        });
        $scenarios = new \PeanutAdmin\Modules\Workflow\Integration\WorkflowScenarioRegistry();
        $scenarios->register('module.sample', 'approval', 'subject.item', $this->scenarios, $access);
        $resolver = new \PeanutAdmin\Modules\Workflow\Integration\ScenarioWorkflowAuthorizationResolver($scenarios);
        $basis = $this->context();
        $authorized = $resolver->authorize($basis, 'subject.item', 'approve', ['subject.item.approve'], 'subject-1');
        self::assertSame($basis->tenantContext, $authorized->tenantContext);
        self::assertSame(['subject-1'], $authorized->targets[0]->targetIds);
        $this->pdo->exec("DELETE binding FROM pa_role_permission binding JOIN pa_permission permission ON permission.id=binding.permission_id WHERE binding.tenant_id=1 AND permission.`key`='subject.item.approve'");
        try {
            $resolver->authorize($basis, 'subject.item', 'approve', ['subject.item.approve'], 'subject-1');
            self::fail('The real IAM projection must deny the removed permission.');
        } catch (\PeanutAdmin\Kernel\Api\ApiException $exception) {
            self::assertSame('AUTHZ_PERMISSION_DENIED', $exception->errorCode);
            self::assertSame(403, $exception->httpStatus);
        }
        $this->pdo->exec("UPDATE test_workflow_subject SET tenant_id=2 WHERE subject_key='subject-1'");
        WorkflowCapabilityCompositionTest::assertWorkflowFailure('WORKFLOW_SUBJECT_NOT_FOUND', fn() => $resolver->authorize($basis, 'subject.item', 'read', ['subject.item.read'], 'subject-1'));
        self::assertFalse($this->pdo->inTransaction());
    }

    public function testRealTaskPublicationIsAtomicReplaySafeAndExecutesTheRegisteredAction(): void
    {
        $tasks = $this->nativeTasks();
        $this->definitions = new WorkflowDefinitionService($this->transactions, $this->scenarios, $this->assignments, new AuditService(), $this->lifecycle, new WorkflowDefinitionValidator($this->scenarios, $tasks, $this->availability), $this->authorization);
        $this->commands = new WorkflowCommands($this->transactions, $this->authorization, $this->assignments, $this->scenarios, $this->queries, new AuditService(), $this->lifecycle, tasks: $tasks);
        $this->publish(WorkflowGraphTest::validGraph());
        $instance = $this->start();
        [$item, $request] = $this->request($instance);
        $this->assertRollback('task_job', 'INSERT', fn() => $this->commands->decide($this->context(member: 12), $item, $request, 'workflow-approve'));
        self::assertSame(0, (int) Db::name('task_job')->count());
        $approved = $this->commands->decide($this->context(member: 12), $item, $request, 'workflow-approve');
        self::assertSame('pending', $approved['active_nodes'][0]['status']);
        self::assertSame(1, (int) Db::name('task_job')->count());
        $job = Db::name('task_job')->find();
        $node = Db::name('workflow_node_execution')->where('node_key', 'action')->find();
        self::assertSame($job['job_key'], $node['task_job_key']);
        self::assertSame('workflow.finalize', $job['task_type']);
        self::assertSame($approved, $this->commands->decide($this->context(member: 12), $item, $request, 'workflow-approve'));
        self::assertSame(1, (int) Db::name('task_job')->count());
        $this->pdo->exec('CREATE TABLE test_workflow_action (effect_key VARCHAR(200) PRIMARY KEY) ENGINE=InnoDB');
        $executions = 0;
        $this->scenarios->method('executeAction')->willReturnCallback(function ($context, $action, $version, $input, $effect) use (&$executions, $node) {
            self::assertTrue($this->pdo->inTransaction());
            self::assertSame('subject.item.finalize', $action);
            self::assertSame($node['effect_key'], $effect);
            ++$executions;
            Db::table('test_workflow_action')->insert(['effect_key' => $effect]);
            if ($executions === 1) {
                throw new \RuntimeException('provider-secret database detail');
            }
            return ['output' => []];
        });
        $payload = json_decode($job['payload_json'], true, flags: JSON_THROW_ON_ERROR);
        $fences = 0;
        $execution = new \PeanutAdmin\Modules\Task\Contract\JobExecution($job['job_key'], 1, 1, $payload, static function (): void {}, function () use (&$fences): void {
            self::assertTrue($this->pdo->inTransaction());
            ++$fences;
        });
        try {
            $this->commands->executeServiceTask($this->context(member: 12), $execution);
            self::fail('The injected business action failure must abort its business transaction.');
        } catch (\RuntimeException $exception) {
            self::assertSame('provider-secret database detail', $exception->getMessage());
        }
        self::assertSame(0, (int) Db::table('test_workflow_action')->count());
        $blocked = $this->queries->instance($this->context(), $instance['instance_key']);
        self::assertSame('blocked', $blocked['status']);
        self::assertSame('Business action failed; inspect the original Task job.', $blocked['blocker']['message']);
        self::assertSame($job['job_key'], Db::name('workflow_node_execution')->where('node_key', 'action')->value('task_job_key'));
        self::assertNull(Db::name('workflow_node_execution')->where('node_key', 'action')->value('effect_receipt_json'));
        self::assertFalse($this->pdo->inTransaction());
        $this->commands->executeServiceTask($this->context(member: 12), $execution);
        self::assertSame('completed', $this->queries->instance($this->context(), $instance['instance_key'])['status']);
        self::assertSame(2, $executions);
        self::assertSame(1, (int) Db::table('test_workflow_action')->count());
        self::assertGreaterThanOrEqual(3, $fences);
        $this->commands->executeServiceTask($this->context(member: 12), $execution);
        self::assertSame(2, $executions);
        self::assertSame(1, (int) Db::table('test_workflow_action')->count());
    }

    public function testCommittedNotificationFactUsesNativeOutboxAndTaskWithoutReplayDuplicates(): void
    {
        $notificationTask = $this->createStub(\PeanutAdmin\Modules\Task\Contract\TaskSubmissionProvider::class);
        $notificationTask->method('taskType')->willReturn('workflow.inbox');
        $notificationTask->method('resourceKey')->willReturn('subject.item');
        $notificationTask->method('operation')->willReturn('approve');
        $notificationTask->method('build')->willReturnCallback(static fn($context, $input) => new \PeanutAdmin\Modules\Task\Contract\TaskSubmission('notification.inbox', $input));
        $tasks = $this->nativeTasks($notificationTask);
        $server = dirname(__DIR__, 6);
        require_once $server . '/app/modules/peanut/notification/database/migrations/20261102000001_initial_notification_schema.php';
        $migration = new \InitialNotificationSchema('testing', 20261102000001, new \think\console\Input([]), new \think\console\Output('buffer'));
        $migration->setAdapter(new \Phinx\Db\Adapter\MysqlAdapter(['connection' => $this->pdo]));
        $migration->up();
        $this->pdo->exec("INSERT INTO pa_tenant_session (session_key,tenant_id,account_id,tenant_member_id,client_key,account_security_revision,tenant_security_revision,member_security_revision,issued_at,last_seen_at,idle_expires_at,absolute_expires_at,created_at,updated_at) VALUES ('01J00000000000000000000000',1,111,11,'admin-web',1,1,1,UTC_TIMESTAMP(3),UTC_TIMESTAMP(3),'2035-01-01','2035-01-01',UTC_TIMESTAMP(3),UTC_TIMESTAMP(3))");
        $producer = $this->createStub(\PeanutAdmin\Modules\Notification\Contract\NotificationProducer::class);
        $producer->method('key')->willReturn('workflow.approved');
        $producer->method('resourceKey')->willReturn('subject.item');
        $producer->method('operation')->willReturn('approve');
        $producer->method('taskType')->willReturn('workflow.inbox');
        $producer->method('handlerKey')->willReturn('notification.inbox');
        $producer->method('authorize')->willReturnCallback(fn($tenant, $identity) => WorkflowCapabilityCompositionTest::subjectContext($this->context(), $identity->businessKey, 'approve'));
        $producer->method('authorizeRead')->willReturnCallback(fn($tenant, $identity) => WorkflowCapabilityCompositionTest::subjectContext($this->context(), $identity->businessKey));
        $producer->method('publication')->willReturnCallback(function ($context, $identity): array {
            $fact = $this->queries->notificationFact($context, $identity->producerKey, $identity->eventKey, $identity->businessKey);
            return ['template' => ['template_key' => 'workflow.approved', 'revision' => 1, 'name' => 'Approved', 'subject_template' => 'Workflow approved', 'body_template' => '{{decision}}: {{title}}', 'channels' => ['inbox'], 'variables' => ['title', 'decision']], 'recipients' => $fact['recipients'], 'file_keys' => $fact['file_keys']];
        });
        $producers = new \PeanutAdmin\Modules\Notification\Contract\NotificationProducerRegistry();
        $producers->register($producer);
        $this->scenarioMetadata['capabilities'][] = 'workflow.notification';
        $this->scenarioMetadata['notification'] = ['producer_key' => 'workflow.approved'];
        $graph = WorkflowGraphTest::validGraph(true);
        $graph['required_capabilities'][] = 'workflow.notification';
        $this->definitions = new WorkflowDefinitionService($this->transactions, $this->scenarios, $this->assignments, new AuditService(), $this->lifecycle, new WorkflowDefinitionValidator($this->scenarios, $tasks, $this->availability, $producers), $this->authorization);
        $this->commands = new WorkflowCommands($this->transactions, $this->authorization, $this->assignments, $this->scenarios, $this->queries, new AuditService(), $this->lifecycle, tasks: $tasks);
        $members = new \PeanutAdmin\Modules\Identity\Membership\Query\ThinkPhpTenantMemberDirectory();
        $store = new \PeanutAdmin\Modules\Notification\Delivery\Persistence\NotificationStore($members);
        $recipients = $this->createStub(\PeanutAdmin\Modules\Notification\Delivery\Application\RecipientResolver::class);
        $recipients->method('snapshot')->willReturn(new \PeanutAdmin\Modules\Notification\Delivery\Application\RecipientSnapshot(11, 111, 'Initiator', null, null));
        $async = new \PeanutAdmin\Modules\Notification\Infrastructure\Authorization\NotificationAsyncAuthorization($this->createStub(\app\common\contract\authorization\AdminAuthorizationQuery::class), $this->createStub(\PeanutAdmin\Modules\Identity\Contract\TenantSessionAccess::class), $members, $store, $producers, $this->availability);
        $notifications = new \PeanutAdmin\Modules\Notification\Delivery\Application\NotificationService($store, $recipients, null, new \PeanutAdmin\Modules\Notification\Delivery\Application\TemplateRenderer(), $producers, $this->availability, new \PeanutAdmin\Modules\Notification\Delivery\Task\InboxTaskHandler($store, $async), $tasks);
        $delivery = new \PeanutAdmin\Modules\Workflow\Application\WorkflowNotificationDelivery($this->lifecycle, $this->authorization, $this->transactions, new AuditService(), $notifications);
        $this->publish($graph);
        $instance = $this->start();
        [$item, $request] = $this->request($instance);
        $approved = $this->commands->decide($this->context(member: 12), $item, $request, 'workflow-approve');
        self::assertSame(0, (int) Db::name('notification_message')->count());
        self::assertSame(1, (int) Db::name('workflow_event')->whereNotNull('notification_fact_json')->count());
        $this->pdo->exec("CREATE TRIGGER workflow_notification_failure BEFORE INSERT ON pa_notification_message FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='provider-secret notification detail'");
        try {
            $delivery->dispatchCommitted($this->context(), $instance['instance_key']);
        } finally {
            $this->pdo->exec('DROP TRIGGER workflow_notification_failure');
        }
        self::assertSame(0, (int) Db::name('notification_message')->count());
        self::assertSame(0, (int) Db::name('notification_outbox')->count());
        self::assertSame(0, (int) Db::name('task_job')->count());
        self::assertSame('NOTIFICATION_DELIVERY_PENDING', Db::name('workflow_event')->whereNotNull('notification_fact_json')->value('notification_last_error'));
        self::assertSame($approved['revision'], $this->queries->instance($this->context(member: 12), $instance['instance_key'])['revision']);
        $delivery->dispatchCommitted($this->context(), $instance['instance_key']);
        self::assertSame(1, (int) Db::name('notification_message')->count());
        self::assertSame(1, (int) Db::name('notification_outbox')->count());
        self::assertSame(1, (int) Db::name('task_job')->count());
        self::assertSame($approved, $this->commands->decide($this->context(member: 12), $item, $request, 'workflow-approve'));
        $delivery->dispatchCommitted($this->context(), $instance['instance_key']);
        self::assertSame(1, (int) Db::name('notification_message')->count());
        self::assertSame(1, (int) Db::name('notification_outbox')->count());
        self::assertSame(1, (int) Db::name('task_job')->count());
        self::assertNull(Db::name('workflow_event')->whereNotNull('notification_fact_json')->value('notification_last_error'));
        self::assertFalse($this->pdo->inTransaction());
    }

    private function nativeTasks(?\PeanutAdmin\Modules\Task\Contract\TaskSubmissionProvider $additional = null): \PeanutAdmin\Modules\Task\Contract\TrustedJobPublisher
    {
        $server = dirname(__DIR__, 6);
        require_once $server . '/app/modules/peanut/task/database/migrations/20261101010006_initial_task_schema.php';
        $migration = new \InitialTaskSchema('testing', 20261101010006, new \think\console\Input([]), new \think\console\Output('buffer'));
        $migration->setAdapter(new \Phinx\Db\Adapter\MysqlAdapter(['connection' => $this->pdo]));
        $migration->up();
        $this->additionalStateTables = ['task_job', 'task_job_attempt', 'task_job_event'];
        $manifest = \PeanutAdmin\Kernel\Module\ManifestDocument::fromObject($server . '/app/modules/peanut/workflow', json_decode(file_get_contents($server . '/app/modules/peanut/workflow/module.json'), flags: JSON_THROW_ON_ERROR));
        $registry = new CompiledModuleRegistry([$manifest], [], [], [], 'workflow-native-task');
        $this->pdo->exec("INSERT INTO pa_module_installation (module_key,installed_version,manifest_schema_version,manifest_digest,status,created_at,updated_at) VALUES ('peanut.workflow','" . $manifest->data['version'] . "',1,'" . $manifest->digest . "','active',UTC_TIMESTAMP(3),UTC_TIMESTAMP(3))");
        $entitlements = new TenantEntitlementService($registry);
        $provider = $this->createStub(\PeanutAdmin\Modules\Task\Contract\TaskSubmissionProvider::class);
        $provider->method('taskType')->willReturn('workflow.finalize');
        $provider->method('resourceKey')->willReturn('subject.item');
        $provider->method('operation')->willReturn('finalize');
        $provider->method('build')->willReturnCallback(static fn($context, $input) => new \PeanutAdmin\Modules\Task\Contract\TaskSubmission('workflow.finalize', $input));
        $operations = $this->createStub(\PeanutAdmin\DataPermission\Catalog\ResourceOperationCatalog::class);
        $operations->method('find')->willReturn(new \PeanutAdmin\DataPermission\Catalog\ResourceOperation(1, 1, 'subject.item', 'peanut.workflow', 'workflow-test', 'tenant', 'finalize', 'write', 'single', 'all', ['subject.item.finalize'], []));
        return new \PeanutAdmin\Modules\Task\Contract\TrustedJobPublisher(new \PeanutAdmin\Modules\Task\Job\Persistence\TaskJobStore(), new \PeanutAdmin\Modules\Task\Job\Submission\TaskSubmissionRegistry($additional === null ? [$provider] : [$provider, $additional]), new \PeanutAdmin\Kernel\Async\TrustedEnvelopeCodec(str_repeat('w', 32)), $operations, $entitlements);
    }

    public function testConcurrentHumanTransitionHasExactlyOneWinner(): void
    {
        if (!function_exists('pcntl_fork')) {
            self::markTestSkipped('The concurrent MySQL assertion requires native pcntl.');
        }
        $this->publish();
        $instance = $this->start();
        [$item, $request] = $this->request($instance);
        $children = [];
        $sockets = [];
        for ($index = 0; $index < 2; ++$index) {
            $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
            self::assertIsArray($pair);
            $pid = pcntl_fork();
            self::assertNotSame(-1, $pid);
            if ($pid === 0) {
                fclose($pair[0]);
                try {
                    // The parent already proved this exact registry-backed database is owned.
                    $pdo = $this->reconnect();
                    \ThinkPhpTestConnection::fromPdo($pdo);
                    fwrite($pair[1], "ready\n");
                    fgets($pair[1]);
                    $this->commands->decide($this->context(member: 12), $item, $request, 'workflow-concurrent-' . $index);
                    fwrite($pair[1], "success\n");
                    exit(0);
                } catch (WorkflowException $exception) {
                    fwrite($pair[1], $exception->errorCode . "\n");
                    exit(0);
                } catch (Throwable $exception) {
                    fwrite($pair[1], get_class($exception) . "\n");
                    exit(1);
                }
            }
            fclose($pair[1]);
            stream_set_timeout($pair[0], 30);
            $sockets[] = $pair[0];
            $children[] = $pid;
        }
        foreach ($sockets as $socket) { self::assertSame("ready\n", fgets($socket)); }
        foreach ($sockets as $socket) { fwrite($socket, "go\n"); }
        $outcomes = [];
        foreach ($sockets as $socket) {
            $outcomes[] = trim((string) fgets($socket));
            fclose($socket);
        }
        foreach ($children as $pid) {
            pcntl_waitpid($pid, $status);
            self::assertTrue(pcntl_wifexited($status));
            self::assertSame(0, pcntl_wexitstatus($status));
        }
        // Forked PDO destruction closes inherited server sessions; restore only this fixture.
        $this->pdo = $this->reconnect();
        \ThinkPhpTestConnection::fromPdo($this->pdo);
        sort($outcomes);
        self::assertSame(['WORKFLOW_INSTANCE_CONFLICT', 'success'], $outcomes);
        self::assertSame(2, (int) Db::name('workflow_instance')->value('revision'));
        self::assertSame(2, (int) Db::name('workflow_event')->count());
    }

    private function reconnect(): PDO
    {
        $pdo = new PDO('mysql:host=' . getenv('DB_HOST') . ';port=' . getenv('DB_PORT') . ';dbname=' . \RegisteredMysqlTestResource::configuredDatabaseName() . ';charset=utf8mb4', (string) getenv('DB_USER'), (string) getenv('DB_PASS'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);
        \RegisteredMysqlTestResource::assertSelectedDatabase($pdo, \RegisteredMysqlTestResource::configuredDatabaseName());
        return $pdo;
    }

    public function testQueriesAreTenantBoundPaginatedAndFieldMinimized(): void
    {
        $this->publish();
        $instance = $this->start();
        $page = $this->queries->definitions($this->context(), 1, 1);
        self::assertSame(1, $page['total']);
        self::assertCount(1, $page['items']);
        self::assertSame(0, $this->queries->definitions($this->context(tenant: 2))['total']);
        self::assertArrayNotHasKey('tenant_id', $instance);
        self::assertArrayNotHasKey('snapshot_json', $instance);
        self::assertArrayNotHasKey('subject_revision_sha256', $instance);
        self::assertArrayNotHasKey('tenant_id', $page['items'][0]);
        self::assertCount(1, $this->queries->events($this->context(), $instance['instance_key'], 0, 1));
        self::assertSame([], $this->queries->events($this->context(), $instance['instance_key'], 1, 1));
        WorkflowCapabilityCompositionTest::assertWorkflowFailure('WORKFLOW_DEFINITION_INVALID', fn() => $this->queries->definitions($this->context(), 0));
    }

    public function testPersistedGraphDigestAndInternalJsonCorruptionFailClosed(): void
    {
        $this->publish();
        $instance = $this->start();
        $this->pdo->exec("UPDATE pa_workflow_definition_version SET graph_sha256 = REPEAT('0',64)");
        WorkflowCapabilityCompositionTest::assertWorkflowFailure('INTERNAL_ERROR', fn() => $this->queries->instance($this->context(), $instance['instance_key']));
        $this->pdo->exec("UPDATE pa_workflow_definition_version SET graph_sha256 = (SELECT draft_graph_sha256 FROM pa_workflow_definition LIMIT 1)");
        $this->pdo->exec("UPDATE pa_workflow_event SET metadata_json = JSON_QUOTE('internal-corruption')");
        $this->assertRedactedHttpFailure(fn() => $this->queries->events($this->context(), $instance['instance_key']));
    }

    public function testQueryMasksDatabaseDetailsAtTheCurrentHttpErrorBoundary(): void
    {
        $this->publish();
        $instance = $this->start();
        $this->pdo->exec('RENAME TABLE pa_workflow_instance TO test_workflow_instance_unavailable');
        try {
            $this->assertRedactedHttpFailure(fn() => $this->queries->instance($this->context(), $instance['instance_key']));
        } finally {
            $this->pdo->exec('RENAME TABLE test_workflow_instance_unavailable TO pa_workflow_instance');
        }
    }

    private function assertRedactedHttpFailure(callable $query): void
    {
        // Error translation now belongs to the current controller boundary, not a retired repository.
        $class = new \ReflectionClass(\PeanutAdmin\Modules\Workflow\Controller\WorkflowController::class);
        $controller = $class->newInstanceWithoutConstructor();
        try {
            $class->getMethod('respond')->invoke($controller, $query);
            self::fail('A persisted/query failure must produce a redacted error.');
        } catch (\app\common\http\ApiProblem $exception) {
            self::assertSame('INTERNAL_ERROR', $exception->errorCode);
            self::assertSame(500, $exception->httpStatus);
            self::assertSame('The workflow request could not be completed.', $exception->getMessage());
            self::assertStringNotContainsString('pa_workflow', $exception->getMessage());
            self::assertStringNotContainsString('internal-corruption', $exception->getMessage());
        }
    }

    public function testArchivedAndCrossTenantAttachmentsFailBeforeWorkflowWrites(): void
    {
        $this->publish();
        $attachments = $this->createStub(WorkflowAttachmentResolver::class);
        $attachments->method('snapshot')->willThrowException(WorkflowException::attachmentUnavailable());
        $this->commands = $this->makeCommands($attachments);
        foreach (['archived', 'cross-tenant'] as $reason) {
            $before = $this->state();
            WorkflowCapabilityCompositionTest::assertWorkflowFailure('WORKFLOW_ATTACHMENT_UNAVAILABLE', fn() => $this->start('subject-1', 'workflow-' . $reason, ['file_' . str_repeat('a', 32)]));
            self::assertSame($before, $this->state());
        }
    }
}
