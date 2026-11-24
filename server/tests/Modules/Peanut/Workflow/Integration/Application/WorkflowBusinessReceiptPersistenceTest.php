<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Workflow\Tests\Integration\Application;

use app\common\composition\ModuleComposition;
use app\common\execution\CurrentExecutionContext;
use app\common\execution\ExecutionContextStore;
use app\common\services\CrontabCommandService;
use app\platform\composition\module\ModuleDefinitionRegistryFactory;
use app\platform\infrastructure\module\ModuleLockResolver;
use PDO;
use PeanutAdmin\Fixtures\WorkflowBusiness\Package;
use PeanutAdmin\Fixtures\WorkflowBusiness\Service\WorkflowBusinessAccess;
use PeanutAdmin\Fixtures\WorkflowBusiness\Service\WorkflowBusinessService;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Authorization\Application\AdminAccessException;
use PeanutAdmin\Kernel\Authorization\TenantAuthorizationEvaluator;
use PeanutAdmin\Kernel\Authorization\TenantAuthorizationRepository;
use PeanutAdmin\Kernel\Context\AuthorizationDecision;
use PeanutAdmin\Kernel\Context\AuthorizedOperationContext;
use PeanutAdmin\Kernel\Module\CompiledModuleRegistry;
use PeanutAdmin\Kernel\Module\ModuleRuntimeRepository;
use PeanutAdmin\Modules\Identity\Authorization\ModuleAuthorizationCatalogSynchronizer;
use PeanutAdmin\Modules\Identity\Authorization\Persistence\ThinkPhpAuthorizationCatalogRepository;
use PeanutAdmin\Modules\Identity\Authorization\ThinkPhpTenantAuthorizationRepository;
use PeanutAdmin\Modules\Identity\Contract\TenantSessionAccess;
use PeanutAdmin\Modules\Identity\Module\Persistence\ThinkPhpModuleRuntimeRepository;
use PeanutAdmin\Modules\Task\Contract\TaskJobRuntime;
use PeanutAdmin\Modules\Workflow\Application\WorkflowCommands;
use PeanutAdmin\Modules\Workflow\Application\WorkflowDefinitionService;
use PeanutAdmin\Modules\Workflow\Application\WorkflowException;
use PeanutAdmin\Modules\Workflow\Application\WorkflowQueries;
use PeanutAdmin\Modules\Workflow\Definition\WorkflowGraph;
use Phinx\Db\Adapter\MysqlAdapter;
use PHPUnit\Framework\TestCase;
use think\App;
use think\console\Input;
use think\console\Output;
use think\Container;
use think\migration\Migrator;
use Throwable;

require_once dirname(__DIR__, 5) . '/Support/RegisteredMysqlTestResource.php';
require_once dirname(__DIR__, 5) . '/Support/ThinkPhpTestConnection.php';
require_once dirname(__DIR__, 5) . '/Support/IdentityMysqlFixture.php';

/** Persists the optional Code receipt scenario in one guarded native PHP process. */
final class WorkflowBusinessReceiptPersistenceTest extends TestCase
{
    private const SCENE = 'receipt';
    private const DEPARTMENT = 'workflow-receiving';
    private const SKU = 'SYNTHETIC-RECEIPT-SKU';
    private const GRAPH_SHA256 = 'ee5cdd62d3fd0fb472042b9f21451aee02ae5c1e25d48919dd75f0592c67b430';
    private const MODULES = ['identity', 'artifact_revision', 'workflow', 'task'];

    private PDO $pdo;
    private string $database;
    private bool $created;
    private bool $failed = false;
    private string $stage = 'guard';
    private array $evidence = [];

    public function testReceiptPersistsThroughNativeWorkflowAndTask(): void
    {
        if (getenv('PEANUT_INTEGRATION') !== '1') {
            self::markTestSkipped('Requires a registered isolated MySQL fixture.');
        }

        try {
            $server = dirname(__DIR__, 6);
            $fixture = $this->fixtureRoot();
            // Keep CiBootstrap's explicit environment available for the native cleanup guard.
            $app = Container::getInstance();
            if (!$app instanceof App || rtrim($app->getRootPath(), DIRECTORY_SEPARATOR) !== $server
                || !getenv('PEANUT_SERVER_ENV_FILE')) {
                throw new \LogicException('The native PHP test entry requires an explicit --env-file.');
            }
            $this->database = \RegisteredMysqlTestResource::configuredDatabaseName();
            [$this->pdo, $this->created] = \RegisteredMysqlTestResource::openEmptyDatabase($this->database);
            \ThinkPhpTestConnection::fromPdo($this->pdo);

            $this->stage = 'compose';
            $registry = $this->compose($app, $server, $fixture);
            $this->stage = 'migrate';
            $this->install($server, $fixture);
            $this->stage = 'seed';
            $this->seed($registry);

            $this->stage = 'create-confirm-publish';
            $business = $app->make(WorkflowBusinessService::class);
            $definitions = $app->make(WorkflowDefinitionService::class);
            $commands = $app->make(WorkflowCommands::class);
            $queries = $app->make(WorkflowQueries::class);
            $resource = Package::resource(self::SCENE);
            $owner = $this->session($app, 1, 11);
            $keeper = $this->session($app, 1, 12);
            $outsider = $this->session($app, 2, 21);
            $receipt = $business->create($this->basis($app, $owner, $resource, 'create', Package::MODULE_KEY . '.create'), self::SCENE, [
                'title' => 'Synthetic warehouse receipt', 'amount_minor' => 3450, 'sku' => self::SKU, 'quantity' => 3,
                'business_department_key' => self::DEPARTMENT,
            ]);
            $objectKey = $receipt['object_key'];
            $confirmed = $business->confirm(
                $this->basis($app, $owner, $resource, 'confirm', Package::MODULE_KEY . '.confirm'),
                self::SCENE, $objectKey, (int) $receipt['revision'], 'receipt-confirm-1',
            );

            $template = json_decode((string) file_get_contents($fixture . '/resources/workflows/receipt.json'), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame(self::GRAPH_SHA256, $template['graph_sha256']);
            self::assertSame(self::GRAPH_SHA256, WorkflowGraph::fromArray($template['graph'])->sha256);
            $draft = $definitions->saveDraft(
                $this->basis($app, $owner, 'peanut.workflow.definition', 'write', 'peanut.workflow.definition.write'),
                Package::MODULE_KEY, self::SCENE,
                ['expected_revision' => null, 'graph' => $template['graph'], 'layout' => ['nodes' => [], 'viewport' => ['x' => 0, 'y' => 0, 'zoom' => 1]]],
                'workflow-receipt-draft-1',
            );
            $published = $definitions->publish(
                $this->basis($app, $owner, 'peanut.workflow.definition', 'publish', 'peanut.workflow.definition.publish'),
                Package::MODULE_KEY, self::SCENE, ['expected_revision' => $draft['draft_revision']], 'receipt-publish-1',
            );
            self::assertSame(1, $published['active_version']);
            $version = $this->row('SELECT graph_sha256 FROM pa_workflow_definition_version WHERE tenant_id = ? AND definition_id = ? AND version = ?', [1, $published['definition_id'], 1]);
            self::assertSame(self::GRAPH_SHA256, $version['graph_sha256']);
            $this->evidence['published_graph_sha256'] = $version['graph_sha256'];

            $this->stage = 'start-claim-form-complete';
            $startContext = $this->basis($app, $owner, $resource, 'start', 'peanut.workflow.instance.start');
            $startRequest = [
                'module_key' => Package::MODULE_KEY, 'workflow_key' => self::SCENE, 'subject_type' => $resource,
                'subject_key' => $objectKey, 'subject_revision_key' => $confirmed['revision_key'],
                'expected_revision' => $published['draft_revision'], 'business_department_key' => self::DEPARTMENT, 'attachment_file_keys' => [],
            ];
            $started = $commands->start($startContext, $startRequest, 'workflow-receipt-start-1');
            self::assertSame('running', $started['status'], json_encode($started['blocker'], JSON_THROW_ON_ERROR));
            self::assertSame(WorkflowGraph::json($started), WorkflowGraph::json($commands->start($startContext, $startRequest, 'workflow-receipt-start-1')));
            $instanceKey = $started['instance_key'];
            $this->evidence['instance_key'] = $instanceKey;
            $initial = $this->row('SELECT id, snapshot_json, subject_revision_key, subject_revision_sha256 FROM pa_workflow_instance WHERE tenant_id = ? AND instance_key = ?', [1, $instanceKey]);
            self::assertSame($confirmed['sha256'], $initial['subject_revision_sha256']);
            $this->evidence['snapshot_sha256'] = $initial['subject_revision_sha256'];

            $keeperRead = $this->basis($app, $keeper, $resource, 'read', 'peanut.workflow.instance.read');
            $inbox = $queries->inbox($keeperRead, 'claimable');
            self::assertSame(1, $inbox['total']);
            $work = $inbox['items'][0];
            self::assertSame([$instanceKey, 'handle', null, ['candidate']], [$work['instance_key'], $work['node_key'], $work['assignee_member_id'], $work['relations']]);
            $claim = $commands->claimTask(
                $this->basis($app, $keeper, $resource, 'claim', 'peanut.workflow.task.claim'),
                $work['work_item_key'], self::workRequest($work), 'workflow-receipt-claim-1',
            );
            self::assertSame('12', $claim['own_work_items'][0]['assignee_member_id']);
            self::assertSame('assigned', $claim['own_work_items'][0]['assignment_state']);
            $business->saveCompletionForm(
                $this->basis($app, $keeper, $resource, 'complete', Package::MODULE_KEY . '.complete'),
                self::SCENE, $objectKey, (int) $confirmed['revision'],
                ['note' => 'Synthetic acceptance', 'reference' => 'receipt-check', 'result' => 'completed'],
            );
            $completeContext = $this->basis($app, $keeper, $resource, 'complete', 'peanut.workflow.task.complete');
            $completeRequest = self::workRequest($claim['own_work_items'][0]) + [
                'comment' => 'Warehouse keeper accepted synthetic stock', 'attachment_file_keys' => [], 'input' => ['result' => 'completed'],
            ];
            $queued = $commands->completeTask($completeContext, $work['work_item_key'], $completeRequest, 'receipt-complete-1');
            self::assertSame('running', $queued['status'], json_encode($queued['blocker'], JSON_THROW_ON_ERROR));
            self::assertSame(WorkflowGraph::json($queued), WorkflowGraph::json($commands->completeTask($completeContext, $work['work_item_key'], $completeRequest, 'receipt-complete-1')));
            $node = $this->row('SELECT status, task_job_key, effect_key, effect_receipt_json FROM pa_workflow_node_execution WHERE tenant_id = ? AND instance_id = ? AND node_key = ?', [1, $initial['id'], 'effect-completed']);
            self::assertSame('pending', $node['status']);
            self::assertNull($node['effect_receipt_json']);
            $jobKey = $node['task_job_key'];
            $effectKey = $node['effect_key'];
            self::assertIsString($jobKey);
            self::assertIsString($effectKey);
            $this->evidence['task_job_key'] = $jobKey;
            $this->evidence['effect_key'] = $effectKey;
            $job = $this->row('SELECT id, task_type, status, created_by_member_id FROM pa_task_job WHERE tenant_id = ? AND job_key = ?', [1, $jobKey]);
            self::assertSame([Package::action(self::SCENE), 'queued', 12], [$job['task_type'], $job['status'], (int) $job['created_by_member_id']]);
            self::assertSame(0, $this->countRows('pa_fixture_workflow_stock_ledger'));

            $this->stage = 'native-worker-and-readback';
            $runtime = $app->make(TaskJobRuntime::class);
            self::assertSame(1, $runtime->runTenant(1, 'synthetic-receipt-worker', $jobKey));
            $terminal = $queries->instance($keeperRead, $instanceKey);
            self::assertSame(['completed', 'completed'], [$terminal['status'], $terminal['result_code']], json_encode($terminal['blocker'], JSON_THROW_ON_ERROR));
            $final = $this->row('SELECT status, result_code, current_node_key, snapshot_json, subject_revision_key, subject_revision_sha256 FROM pa_workflow_instance WHERE tenant_id = ? AND id = ?', [1, $initial['id']]);
            self::assertSame(['completed', 'completed', 'completed'], [$final['status'], $final['result_code'], $final['current_node_key']]);
            foreach (['snapshot_json', 'subject_revision_key', 'subject_revision_sha256'] as $field) {
                self::assertSame($initial[$field], $final[$field]);
            }
            $snapshot = json_decode($final['snapshot_json'], true, 512, JSON_THROW_ON_ERROR);
            self::assertSame([$confirmed['revision_key'], $confirmed['sha256'], self::SKU, 3], [$snapshot['revision_key'], $snapshot['sha256'], $snapshot['fields']['sku'], $snapshot['fields']['quantity']]);
            $payload = $this->row('SELECT payload_json, payload_sha256 FROM pa_fixture_workflow_payload WHERE tenant_id = ? AND object_key = ? AND revision_key = ?', [1, $objectKey, $confirmed['revision_key']]);
            self::assertSame($payload['payload_sha256'], hash('sha256', $payload['payload_json']));

            $this->assertReceipts((int) $initial['id'], (int) $job['id'], $jobKey, $effectKey, $objectKey);
            $this->evidence['terminal'] = $terminal['status'];
            $this->evidence['stock_quantity'] = 3;

            $this->stage = 'replay-and-cross-tenant';
            $before = $this->state();
            self::assertSame(WorkflowGraph::json($queued), WorkflowGraph::json($commands->completeTask($completeContext, $work['work_item_key'], $completeRequest, 'receipt-complete-1')));
            self::assertSame(0, $runtime->runTenant(1, 'synthetic-receipt-worker', $jobKey));
            $foreignContext = $this->basis($app, $outsider, $resource, 'complete', 'peanut.workflow.task.complete');
            try {
                $commands->completeTask($foreignContext, $work['work_item_key'], $completeRequest, 'receipt-foreign-complete-1');
                self::fail('A valid role in the other tenant must not see or complete the work item.');
            } catch (WorkflowException $exception) {
                self::assertSame('WORKFLOW_SUBJECT_NOT_FOUND', $exception->errorCode);
                self::assertSame(404, $exception->httpStatus);
            }
            try {
                $app->make(WorkflowBusinessAccess::class)->authorize($foreignContext, $resource, 'complete', [Package::MODULE_KEY . '.complete'], $objectKey);
                self::fail('The fixture data policy must not expose the other tenant receipt.');
            } catch (AdminAccessException $exception) {
                self::assertSame('RESOURCE_NOT_FOUND', $exception->errorCode);
                self::assertSame(404, $exception->httpStatus);
            }
            self::assertSame($before, $this->state());
            $this->assertReceipts((int) $initial['id'], (int) $job['id'], $jobKey, $effectKey, $objectKey);
            self::assertFalse($this->pdo->inTransaction());
            $this->evidence['cross_tenant'] = 'denied_without_side_effects';
            $this->evidence['row_counts'] = $before;
            $this->report('passed');
        } catch (Throwable $failure) {
            $this->failed = true;
            $this->report('failed');
            throw $failure;
        }
    }

    protected function tearDown(): void
    {
        if (!isset($this->pdo, $this->created)) {
            return;
        }
        try {
            \RegisteredMysqlTestResource::cleanup($this->pdo, $this->database, $this->created);
        } catch (Throwable $cleanup) {
            if (!$this->failed) {
                throw $cleanup;
            }
            $guardCode = preg_match('/^REGISTERED_MYSQL_[A-Z0-9_]+(?::DB_(?:HOST|PORT|NAME|USER|PASS))?$/D', $cleanup->getMessage()) === 1
                ? ' ' . $cleanup->getMessage()
                : '';
            fwrite(STDERR, '[workflow-receipt] Native fixture cleanup also failed (' . $cleanup::class . ')' . $guardCode . ".\n");
        }
    }

    private function fixtureRoot(): string
    {
        $input = getenv('PEANUT_WORKFLOW_BUSINESS_FIXTURE_ROOT');
        $root = is_string($input) && str_starts_with($input, '/') ? realpath($input) : false;
        if ($root === false || !is_file($root . '/module.json')) {
            throw new \LogicException('An explicit existing Workflow business fixture root is required.');
        }
        $manifest = json_decode((string) file_get_contents($root . '/module.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['fixture.workflow-business', '1.0.10'], [$manifest['key'], $manifest['version']]);
        return $root;
    }

    private function compose(App $app, string $server, string $fixture): CompiledModuleRegistry
    {
        $app->config->set(['mode' => 'multi-tenant'], 'deployment');
        $app->config->set(['signing_key' => random_bytes(32), 'worker_limit' => 1], 'async');
        $locked = (new ModuleLockResolver($server, '../modules.lock'))->moduleRoots();
        $roots = [];
        foreach (self::MODULES as $module) {
            $root = realpath($server . '/app/modules/peanut/' . $module);
            self::assertContains($root, $locked, 'The scenario prerequisite must be resolved by the Module source lock.');
            $roots[] = $root;
        }
        $registry = (new ModuleDefinitionRegistryFactory($server))->fromDeploymentConfig([
            'roots' => [...$roots, $fixture], 'kernel_version' => '1.0.0',
            'registered_client_keys' => ['admin-web', 'platform-web', 'pc-web', 'uniapp'],
        ]);
        $app->instance(CompiledModuleRegistry::class, $registry);
        $contexts = new ExecutionContextStore();
        $app->instance(ExecutionContextStore::class, $contexts);
        $app->instance(CurrentExecutionContext::class, new CurrentExecutionContext($contexts));
        $app->instance(CrontabCommandService::class, new CrontabCommandService([], []));
        $app->bind(TenantAuthorizationRepository::class, ThinkPhpTenantAuthorizationRepository::class);
        $app->bind(ModuleRuntimeRepository::class, ThinkPhpModuleRuntimeRepository::class);
        (new ModuleComposition($app))->register($registry);
        return $registry;
    }

    private function install(string $server, string $fixture): void
    {
        \IdentityMysqlFixture::install($this->pdo, array_map(
            static fn(string $module): string => $server . '/app/modules/peanut/' . $module . '/database/migrations',
            ['artifact_revision', 'workflow', 'task'],
        ));
        require_once $server . '/vendor/peanut-admin/core/kernel/database/migrations/20260716010401_create_pa_tenant_idempotency_record.php';
        require_once $fixture . '/database/migrations/20261124000001_create_workflow_business_fixture_schema.php';
        $this->migrate(new \CreatePaTenantIdempotencyRecord('testing', 20260716010401, new Input([]), new Output('buffer')));
        $this->migrate(new \CreateWorkflowBusinessFixtureSchema('testing', 20261124000001, new Input([]), new Output('buffer')));
    }

    private function migrate(Migrator $migration): void
    {
        $migration->setAdapter(new MysqlAdapter(['connection' => $this->pdo, 'name' => $this->database]));
        $migration->up();
    }

    private function seed(CompiledModuleRegistry $registry): void
    {
        (new ModuleAuthorizationCatalogSynchronizer(new ThinkPhpAuthorizationCatalogRepository()))->synchronize($registry);
        $installation = $this->pdo->prepare("INSERT INTO pa_module_installation (module_key, installed_version, manifest_schema_version, manifest_digest, status, created_at, updated_at) VALUES (?, ?, ?, ?, 'active', UTC_TIMESTAMP(3), UTC_TIMESTAMP(3))");
        $planModule = $this->pdo->prepare('INSERT INTO pa_plan_module (plan_id, module_key) VALUES (1, ?)');
        $this->pdo->exec("INSERT INTO pa_plan (id, code, name, created_at, updated_at) VALUES (1, 'receipt-fixture', 'Synthetic receipt fixture', UTC_TIMESTAMP(3), UTC_TIMESTAMP(3))");
        foreach ($registry->modules as $manifest) {
            $installation->execute([$manifest->data['key'], $manifest->data['version'], $manifest->data['schema_version'], $manifest->digest]);
            $planModule->execute([$manifest->data['key']]);
        }
        $tenant = $this->pdo->prepare("INSERT INTO pa_tenant (id, code, name, display_name, status, created_at, updated_at) VALUES (?, ?, 'Synthetic receipt tenant', 'Synthetic receipt tenant', 'active', UTC_TIMESTAMP(3), UTC_TIMESTAMP(3))");
        $license = $this->pdo->prepare("INSERT INTO pa_tenant_license (tenant_id, plan_id, status, effective_at, created_at, updated_at) VALUES (?, 1, 'active', DATE_SUB(UTC_TIMESTAMP(3), INTERVAL 1 DAY), UTC_TIMESTAMP(3), UTC_TIMESTAMP(3))");
        $department = $this->pdo->prepare("INSERT INTO pa_department (id, tenant_id, code, name, created_at, updated_at) VALUES (?, ?, ?, 'Synthetic receiving department', UTC_TIMESTAMP(3), UTC_TIMESTAMP(3))");
        foreach ([1, 2] as $tenantId) {
            $tenant->execute([$tenantId, 'synthetic-receipt-' . $tenantId]);
            $license->execute([$tenantId]);
            $department->execute([$tenantId, $tenantId, self::DEPARTMENT]);
        }
        $account = $this->pdo->prepare("INSERT INTO pa_account (id, display_name, status, created_at, updated_at) VALUES (?, 'Synthetic receipt member', 'active', UTC_TIMESTAMP(3), UTC_TIMESTAMP(3))");
        $member = $this->pdo->prepare("INSERT INTO pa_tenant_member (id, tenant_id, account_id, display_name, primary_department_id, status, created_at, updated_at) VALUES (?, ?, ?, 'Synthetic receipt member', ?, 'active', UTC_TIMESTAMP(3), UTC_TIMESTAMP(3))");
        $role = $this->pdo->prepare("INSERT INTO pa_role (id, tenant_id, `key`, name, created_at, updated_at) VALUES (?, ?, ?, 'Synthetic receipt role', UTC_TIMESTAMP(3), UTC_TIMESTAMP(3))");
        $membership = $this->pdo->prepare('INSERT INTO pa_member_role (tenant_id, tenant_member_id, role_id, assigned_at) VALUES (?, ?, ?, UTC_TIMESTAMP(3))');
        $session = $this->pdo->prepare("INSERT INTO pa_tenant_session (session_key, tenant_id, account_id, tenant_member_id, client_key, status, account_security_revision, tenant_security_revision, member_security_revision, issued_at, last_seen_at, idle_expires_at, absolute_expires_at, created_at, updated_at) VALUES (?, ?, ?, ?, 'admin-web', 'active', 1, 1, 1, UTC_TIMESTAMP(3), UTC_TIMESTAMP(3), DATE_ADD(UTC_TIMESTAMP(3), INTERVAL 1 HOUR), DATE_ADD(UTC_TIMESTAMP(3), INTERVAL 2 HOUR), UTC_TIMESTAMP(3), UTC_TIMESTAMP(3))");
        $grant = $this->pdo->prepare("INSERT INTO pa_role_permission (tenant_id, role_id, permission_id, granted_at) SELECT ?, ?, id, UTC_TIMESTAMP(3) FROM pa_permission WHERE `key` = ? AND status = 'active'");
        $ceiling = $this->pdo->prepare('INSERT IGNORE INTO pa_plan_permission (plan_id, permission_key) VALUES (1, ?)');
        $policy = $this->pdo->prepare("INSERT INTO pa_data_permission (tenant_id, role_id, resource_key, scope_type, created_by_member_id, updated_by_member_id, created_at, updated_at) VALUES (?, ?, ?, 'all', ?, ?, UTC_TIMESTAMP(3), UTC_TIMESTAMP(3))");

        $businessPermissions = static fn(array $operations): array => array_map(static fn(string $operation): string => Package::MODULE_KEY . '.' . $operation, $operations);
        $ownerOperations = ['read', 'create', 'confirm', 'start'];
        $keeperOperations = ['read', 'claim', 'complete', 'receive'];
        $ownerGrants = [...$businessPermissions($ownerOperations), 'peanut.workflow.definition.write', 'peanut.workflow.definition.publish', 'peanut.workflow.instance.start', 'peanut.workflow.instance.read'];
        $keeperGrants = [...$businessPermissions($keeperOperations), 'peanut.workflow.instance.read', 'peanut.workflow.task.claim', 'peanut.workflow.task.complete'];
        $resource = Package::resource(self::SCENE);
        foreach ([
            [1, 11, 'workflow-receipt-owner', $ownerOperations, $ownerGrants],
            [1, 12, 'workflow-warehouse-keeper', $keeperOperations, $keeperGrants],
            [2, 21, 'workflow-warehouse-keeper', $keeperOperations, $keeperGrants],
        ] as [$tenantId, $memberId, $roleKey, $operations, $permissions]) {
            $account->execute([100 + $memberId]);
            $member->execute([$memberId, $tenantId, 100 + $memberId, $tenantId]);
            $role->execute([$memberId, $tenantId, $roleKey]);
            $membership->execute([$tenantId, $memberId, $memberId]);
            $session->execute([self::sessionKey($memberId), $tenantId, 100 + $memberId, $memberId]);
            foreach ($permissions as $permission) {
                $ceiling->execute([$permission]);
                $grant->execute([$tenantId, $memberId, $permission]);
                self::assertSame(1, $grant->rowCount(), 'Expected one native catalog permission for ' . $permission);
            }
            foreach (array_diff($operations, ['create']) as $operation) {
                $policy->execute([$tenantId, $memberId, $resource . ':' . $operation, $memberId, $memberId]);
            }
        }
    }

    private static function sessionKey(int $member): string
    {
        return sprintf('01J%023d', $member);
    }

    private function session(App $app, int $tenant, int $member): TenantContext
    {
        return $app->make(TenantSessionAccess::class)->revalidateSession(
            $tenant, 100 + $member, $member, self::sessionKey($member), 'admin-web', 'synthetic-receipt-' . $member,
        );
    }

    private function basis(App $app, TenantContext $actor, string $resource, string $operation, string $permission): AuthorizedOperationContext
    {
        $app->make(TenantAuthorizationEvaluator::class)->assertAllowed($actor, $permission);
        return AuthorizedOperationContext::fromDecision(AuthorizationDecision::allow(
            $actor, $resource, $operation, [], hash('sha256', $actor->requestId . ':' . $permission . ':' . $actor->authorizationRevision),
        ));
    }

    private static function workRequest(array $work): array
    {
        return [
            'node_execution_key' => $work['node_execution_key'], 'expected_revision' => $work['instance_revision'],
            'expected_node_revision' => $work['node_revision'], 'expected_task_revision' => $work['revision'],
        ];
    }

    private function assertReceipts(int $instanceId, int $jobId, string $jobKey, string $effectKey, string $objectKey): void
    {
        $node = $this->row('SELECT status, task_job_key, effect_key, effect_receipt_json FROM pa_workflow_node_execution WHERE tenant_id = ? AND instance_id = ? AND node_key = ?', [1, $instanceId, 'effect-completed']);
        self::assertSame(['completed', $jobKey, $effectKey], [$node['status'], $node['task_job_key'], $node['effect_key']]);
        $effect = $this->row('SELECT receipt_json FROM pa_fixture_workflow_effect WHERE tenant_id = ? AND effect_key = ? AND action_key = ? AND object_key = ?', [1, $effectKey, Package::action(self::SCENE), $objectKey]);
        $effectReceipt = json_decode($effect['receipt_json'], true, 512, JSON_THROW_ON_ERROR);
        self::assertEquals($effectReceipt, json_decode($node['effect_receipt_json'], true, 512, JSON_THROW_ON_ERROR));
        self::assertSame([$effectKey, $objectKey, 'completed', 3], [$effectReceipt['effect_key'], $effectReceipt['object_key'], $effectReceipt['output']['result'], $effectReceipt['output']['quantity']]);
        $ledger = $this->row('SELECT sku, quantity, actor_member_id FROM pa_fixture_workflow_stock_ledger WHERE tenant_id = ? AND effect_key = ? AND object_key = ?', [1, $effectKey, $objectKey]);
        self::assertSame([self::SKU, 3, 12], [$ledger['sku'], (int) $ledger['quantity'], (int) $ledger['actor_member_id']]);
        $balance = $this->row('SELECT quantity, revision FROM pa_fixture_workflow_stock_balance WHERE tenant_id = ? AND sku = ?', [1, self::SKU]);
        self::assertSame([3, 1], [(int) $balance['quantity'], (int) $balance['revision']]);
        $object = $this->row('SELECT state, result_code FROM pa_fixture_workflow_object WHERE tenant_id = ? AND object_key = ?', [1, $objectKey]);
        self::assertSame(['received', 'completed'], [$object['state'], $object['result_code']]);
        $job = $this->row('SELECT status, completed_at, last_error_code FROM pa_task_job WHERE tenant_id = ? AND id = ?', [1, $jobId]);
        self::assertSame('succeeded', $job['status'], (string) $job['last_error_code']);
        self::assertNotNull($job['completed_at']);
        $attempt = $this->row('SELECT status, completed_at FROM pa_task_job_attempt WHERE tenant_id = ? AND job_id = ? AND attempt_number = 1', [1, $jobId]);
        self::assertSame('succeeded', $attempt['status']);
        self::assertNotNull($attempt['completed_at']);
        self::assertSame(1, $this->countRows('pa_task_job'));
        self::assertSame(1, $this->countRows('pa_task_job_attempt'));
        self::assertSame(1, $this->countRows('pa_fixture_workflow_stock_ledger'));
        self::assertSame(1, $this->countRows('pa_fixture_workflow_stock_balance'));
        $this->evidence['task_receipt'] = ['status' => $job['status'], 'attempt' => 1, 'attempt_status' => $attempt['status']];
    }

    private function state(): array
    {
        $counts = [];
        foreach ([
            'pa_workflow_definition_version', 'pa_workflow_instance', 'pa_workflow_node_execution', 'pa_workflow_work_item',
            'pa_workflow_event', 'pa_workflow_task_identity_link', 'pa_tenant_idempotency_record', 'pa_tenant_audit_event',
            'pa_artifact_revision', 'pa_fixture_workflow_object', 'pa_fixture_workflow_payload', 'pa_fixture_workflow_effect',
            'pa_fixture_workflow_stock_ledger', 'pa_fixture_workflow_stock_balance', 'pa_fixture_workflow_task_actor',
            'pa_task_job', 'pa_task_job_attempt', 'pa_task_job_event',
        ] as $table) {
            $counts[$table] = $this->countRows($table);
        }
        return $counts;
    }

    private function countRows(string $table): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn();
    }

    private function row(string $sql, array $parameters): array
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($parameters);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($row, 'Expected one persisted fixture row.');
        return $row;
    }

    private function report(string $status): void
    {
        try {
            $evidence = ['status' => $status, 'stage' => $this->stage] + $this->evidence;
            if (isset($this->pdo)) {
                $evidence['tables'] = $this->pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
                if ($status === 'failed') {
                    foreach ([
                        'pa_workflow_instance' => 'SELECT instance_key, status, result_code, current_node_key FROM pa_workflow_instance',
                        'pa_workflow_node_execution' => 'SELECT node_key, status, task_job_key, effect_key FROM pa_workflow_node_execution',
                        'pa_task_job' => 'SELECT job_key, status, last_error_code FROM pa_task_job',
                        'pa_task_job_attempt' => 'SELECT attempt_number, status FROM pa_task_job_attempt',
                    ] as $table => $sql) {
                        if (in_array($table, $evidence['tables'], true)) {
                            $evidence[$table] = $this->pdo->query($sql . ' ORDER BY id LIMIT 10')->fetchAll(PDO::FETCH_ASSOC);
                        }
                    }
                }
            }
            fwrite(STDERR, '[workflow-receipt] ' . json_encode($evidence, JSON_THROW_ON_ERROR) . "\n");
        } catch (Throwable $failure) {
            fwrite(STDERR, '[workflow-receipt] Evidence read failed during ' . $this->stage . ' (' . $failure::class . ").\n");
        }
    }
}
