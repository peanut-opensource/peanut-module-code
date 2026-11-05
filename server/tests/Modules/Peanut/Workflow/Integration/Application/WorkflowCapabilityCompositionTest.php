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
use PeanutAdmin\Modules\Workflow\Adapter\WorkflowScenarioProvider;
use PeanutAdmin\Modules\Workflow\Application\WorkflowException;
use PeanutAdmin\Modules\Workflow\Integration\ScenarioWorkflowAuthorizationResolver;
use PeanutAdmin\Modules\Workflow\Integration\WorkflowScenarioAccess;
use PeanutAdmin\Modules\Workflow\Integration\WorkflowScenarioRegistry;
use PHPUnit\Framework\TestCase;

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
