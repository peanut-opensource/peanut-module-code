<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Workflow\Tests\Integration\Application;

use DateTimeImmutable;
use LogicException;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Auth\ValidatedTenantSession;
use PeanutAdmin\Kernel\Context\AuthorizationDecision;
use PeanutAdmin\Kernel\Context\AuthorizedOperationContext;
use PeanutAdmin\Kernel\Context\RequestedTargetSet;
use PeanutAdmin\Kernel\Module\ModuleAvailability;
use PeanutAdmin\Modules\Identity\Contract\TenantModuleStateQueries;
use PeanutAdmin\Modules\Workflow\Adapter\WorkflowAssignmentResolver;
use PeanutAdmin\Modules\Workflow\Adapter\WorkflowScenarioProvider;
use PeanutAdmin\Modules\Workflow\Application\WorkflowException;
use PeanutAdmin\Modules\Workflow\Application\WorkflowLifecycleQueries;
use PeanutAdmin\Modules\Workflow\Application\WorkflowQueries;
use PeanutAdmin\Modules\Workflow\Integration\ScenarioWorkflowAuthorizationResolver;
use PeanutAdmin\Modules\Workflow\Integration\WorkflowScenarioAccess;
use PeanutAdmin\Modules\Workflow\Integration\WorkflowScenarioRegistry;
use PeanutAdmin\Modules\Workflow\Tests\Unit\Definition\WorkflowGraphTest;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/Unit/Definition/WorkflowGraphTest.php';

/** The old generic publisher was replaced by owner-registered scenarios and native Task actions. */
final class WorkflowCapabilityCompositionTest extends TestCase
{
    public function testComposesOnlyTheRegisteredOwnerAndAction(): void
    {
        $context = self::context();
        $provider = $this->createMock(WorkflowScenarioProvider::class);
        $provider->expects(self::once())->method('scenario')->with($context, 'module.sample', 'approval')->willReturn(self::metadata());
        $provider->expects(self::once())->method('executeAction')->with($context, 'subject.item.finalize', 1, ['value' => 2], 'effect-1')->willReturn(['output' => ['done' => true]]);
        $registry = new WorkflowScenarioRegistry();
        $registry->register('module.sample', 'approval', 'subject.item', $provider, $this->createStub(WorkflowScenarioAccess::class), ['subject.item.finalize']);

        self::assertSame(self::metadata(), $registry->scenario($context, 'module.sample', 'approval'));
        self::assertSame(['output' => ['done' => true]], $registry->executeAction($context, 'subject.item.finalize', 1, ['value' => 2], 'effect-1'));
        self::assertCount(1, $registry->registrations());
    }

    public function testMissingProviderAndUndeclaredActionsFailClosed(): void
    {
        $registry = new WorkflowScenarioRegistry();
        self::assertWorkflowFailure('WORKFLOW_DEFINITION_INVALID', fn() => $registry->scenario(self::context(), 'module.sample', 'approval'));
        self::assertWorkflowFailure('WORKFLOW_DEFINITION_INVALID', fn() => $registry->executeAction(self::context(), 'subject.item.finalize', 1, [], 'effect-1'));
        $provider = $this->createStub(WorkflowScenarioProvider::class);
        $provider->method('scenario')->willReturn(self::metadata());
        $registry->register('module.sample', 'approval', 'subject.item', $provider, $this->createStub(WorkflowScenarioAccess::class));
        self::assertWorkflowFailure('WORKFLOW_DEFINITION_INVALID', fn() => $registry->scenario(self::context(), 'module.sample', 'approval'));
    }

    public function testDuplicateScenarioAndActionOwnersCannotReplaceTheOriginal(): void
    {
        $registry = new WorkflowScenarioRegistry();
        $provider = $this->createStub(WorkflowScenarioProvider::class);
        $access = $this->createStub(WorkflowScenarioAccess::class);
        $registry->register('module.sample', 'approval', 'subject.item', $provider, $access, ['subject.item.finalize']);
        foreach ([['module.sample', 'approval', 'subject.item', []], ['other.module', 'approval', 'subject.item', []], ['other.module', 'other', 'other.item', ['subject.item.finalize']]] as [$module, $workflow, $resource, $actions]) {
            try {
                $registry->register($module, $workflow, $resource, $provider, $access, $actions);
                self::fail('A conflicting owner must not replace a trusted registration.');
            } catch (LogicException $exception) {
                self::assertStringContainsString('REGISTRATION_CONFLICT', $exception->getMessage());
            }
            self::assertCount(1, $registry->registrations());
            self::assertSame($access, $registry->access('subject.item'));
        }
    }

    public function testAuthorizationPreservesTheExactTenantTargetAndOperation(): void
    {
        $basis = self::context('peanut.workflow.instance', 'read');
        $access = $this->createMock(WorkflowScenarioAccess::class);
        $expected = self::subjectContext($basis);
        $access->expects(self::once())->method('authorize')->with($basis, 'subject.item', 'read', ['subject.item.read'], 'subject-1')->willReturn($expected);
        $resolver = $this->resolver($access);
        self::assertSame($expected, $resolver->authorize($basis, 'subject.item', 'read', ['subject.item.read'], 'subject-1'));
    }

    public function testAuthorizationRejectsCrossTenantAndWrongTargetReceipts(): void
    {
        $basis = self::context('peanut.workflow.instance', 'read');
        foreach ([self::context(tenant: 2), self::context('other.item'), self::context(operation: 'write'), self::subjectContext($basis, 'other-subject'), self::context()] as $invalid) {
            $access = $this->createStub(WorkflowScenarioAccess::class);
            $access->method('authorize')->willReturn($invalid);
            $resolver = $this->resolver($access);
            self::assertWorkflowFailure('WORKFLOW_ASSIGNMENT_DENIED', fn() => $resolver->authorize($basis, 'subject.item', 'read', ['subject.item.read'], 'subject-1'));
        }
    }

    public function testMissingPermissionAndOwnerDenialPropagateBeforeBusinessAction(): void
    {
        $basis = self::context('peanut.workflow.instance', 'read');
        $access = $this->createMock(WorkflowScenarioAccess::class);
        $access->expects(self::once())->method('authorize')->willThrowException(WorkflowException::subjectNotFound());
        $resolver = $this->resolver($access);
        self::assertWorkflowFailure('WORKFLOW_ASSIGNMENT_DENIED', fn() => $resolver->authorize($basis, 'subject.item', 'read', [], 'subject-1'));
        self::assertWorkflowFailure('WORKFLOW_SUBJECT_NOT_FOUND', fn() => $resolver->authorize($basis, 'subject.item', 'read', ['subject.item.read'], 'subject-1'));
    }

    public function testWorkItemProjectionSharesOnlyIdenticalAuthorizationInputs(): void
    {
        $basis = self::context('peanut.workflow.instance', 'read', member: 12);
        $reviewKeys = ['subject.item.review', 'subject.item.read'];
        $decision = 'peanut.workflow.task.decide';
        $calls = [];
        $reviewAllowed = true;
        $access = $this->createStub(WorkflowScenarioAccess::class);
        $access->method('authorize')->willReturnCallback(function ($context, $resource, $operation, $keys, $subject) use (&$calls, &$reviewAllowed, $decision, $reviewKeys): AuthorizedOperationContext {
            $calls[] = [$context, $resource, $operation, $keys, $subject];
            if (!$reviewAllowed || $operation !== 'review' || $keys !== [$decision, ...$reviewKeys]) {
                throw WorkflowException::assignmentDenied();
            }
            return self::subjectContext($context, $subject, $operation);
        });
        $assignments = $this->createStub(WorkflowAssignmentResolver::class);
        $assignments->method('eligible')->willReturn(true);
        $queries = new WorkflowQueries($this->resolver($access), $assignments,
            new WorkflowLifecycleQueries(new TenantModuleStateQueries(), $this->createStub(ModuleAvailability::class)),
            $this->createStub(WorkflowScenarioProvider::class));
        // This projection takes the selected row as input, independent of the MySQL inbox query.
        $projection = new \ReflectionMethod(WorkflowQueries::class, 'itemSummary');
        $row = [
            'work_item_key' => 'item-1', 'instance_key' => 'instance-1', 'node_execution_key' => 'node-1',
            'node_key' => 'review', 'subject_key' => 'subject-1', 'revision' => 1, 'instance_revision' => 1, 'node_revision' => 1,
            'status' => 'pending', 'node_status' => 'active', 'instance_status' => 'running',
            'initiated_by_member_id' => 11, 'owner_member_id' => 11, 'assignee_member_id' => 12,
            'sources_json' => '[]', 'own_links_json' => '[]', 'assignment_state' => 'assigned', 'decision' => null,
        ];
        foreach ([
            [true, 'review', $reviewKeys, ['approve', 'reject'], false],
            [false, 'review', $reviewKeys, [], false],
            [true, 'withdraw', $reviewKeys, ['approve'], true],
            [true, 'review', ['subject.item.reject'], ['approve'], true],
            [true, 'review', array_reverse($reviewKeys), ['approve'], true],
        ] as [$reviewAllowed, $rejectOperation, $rejectKeys, $actions, $separate]) {
            $calls = [];
            $graph = WorkflowGraphTest::validGraph();
            foreach ($graph['transitions'] as &$edge) {
                if ($edge['key'] === 'approve') {
                    $edge['operation'] = 'review';
                    $edge['permission_keys'] = $reviewKeys;
                } elseif ($edge['key'] === 'reject') {
                    $edge['operation'] = $rejectOperation;
                    $edge['permission_keys'] = $rejectKeys;
                }
            }
            unset($edge);
            $row['graph_json'] = $graph;
            self::assertSame($actions, $projection->invoke($queries, $basis, $row)['available_actions']);
            $expected = [[$basis, 'subject.item', 'review', [$decision, ...$reviewKeys], 'subject-1']];
            if ($separate) {
                $expected[] = [$basis, 'subject.item', $rejectOperation, [$decision, ...$rejectKeys], 'subject-1'];
            }
            self::assertSame($expected, $calls);
        }
    }

    private function resolver(WorkflowScenarioAccess $access): ScenarioWorkflowAuthorizationResolver
    {
        $registry = new WorkflowScenarioRegistry();
        $registry->register('module.sample', 'approval', 'subject.item', $this->createStub(WorkflowScenarioProvider::class), $access);
        return new ScenarioWorkflowAuthorizationResolver($registry);
    }

    public static function context(string $resource = 'subject.item', string $operation = 'read', int $tenant = 1, int $member = 11): AuthorizedOperationContext
    {
        $tenantContext = TenantContext::fromValidatedSession(new ValidatedTenantSession($member, '01J00000000000000000000000', $tenant, 100 + $member, $member, 'admin-web', new DateTimeImmutable('2035-01-01T00:00:00Z'), 1, 1), 'workflow-test');
        return AuthorizedOperationContext::fromDecision(AuthorizationDecision::allow($tenantContext, $resource, $operation, [], hash('sha256', $resource . $operation)));
    }

    public static function subjectContext(AuthorizedOperationContext $basis, string $subject = 'subject-1', string $operation = 'read'): AuthorizedOperationContext
    {
        return AuthorizedOperationContext::fromDecision(AuthorizationDecision::allow($basis->tenantContext, 'subject.item', $operation, [new RequestedTargetSet('subject.item', [$subject])], hash('sha256', 'workflow-test')));
    }

    public static function metadata(): array
    {
        return ['contract_version' => 1, 'fields' => [], 'result_codes' => ['done'], 'capabilities' => ['workflow.approval', 'workflow.any_approve', 'workflow.all_approve', 'workflow.human_task', 'workflow.direct', 'workflow.return', 'workflow.service_task'], 'actions' => ['subject.item.finalize' => ['contract_version' => 1, 'operation' => 'finalize', 'permission_keys' => ['subject.item.finalize'], 'task_type' => 'workflow.finalize', 'input_schema' => ['type' => 'object', 'properties' => [], 'additionalProperties' => false]]]];
    }

    public static function assertWorkflowFailure(string $code, callable $action): void
    {
        try {
            $action();
            self::fail('The operation must fail closed: ' . $code);
        } catch (WorkflowException $exception) {
            self::assertSame($code, $exception->errorCode);
        }
    }
}
