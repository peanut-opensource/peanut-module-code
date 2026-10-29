<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Workflow\Application;

use PeanutAdmin\Kernel\Context\AuthorizedOperationContext;
use PeanutAdmin\Kernel\Tenancy\TenantScope;
use PeanutAdmin\Modules\Workflow\Adapter\WorkflowAuthorizationResolver;
use PeanutAdmin\Modules\Workflow\Definition\WorkflowGraph;
use PeanutAdmin\Modules\Workflow\Persistence\Model\WorkflowEventRecord as Event;
use PeanutAdmin\Modules\Workflow\Persistence\Model\WorkflowInstanceRecord as Instance;
use PeanutAdmin\Modules\Workflow\Persistence\Model\WorkflowDefinitionVersionRecord as Version;
use think\facade\Db;

/** Committed Event facts materialize in the existing Notification outbox; no second queue. */
final readonly class WorkflowNotificationDelivery
{
    public function __construct(private WorkflowLifecycleQueries $lifecycle, private WorkflowAuthorizationResolver $authorization, private WorkflowCommandTransaction $transactions, private \PeanutAdmin\Modules\Identity\Audit\AuditService $audit,
        private ?\PeanutAdmin\Modules\Notification\Delivery\Application\NotificationService $notifications = null,
        private ?\PeanutAdmin\Modules\Task\Contract\TaskJobService $jobs = null) {}

    /** Outer callers must invoke after their actual commit. Native HTTP invokes after command return and PDO proof. */
    public function dispatchCommitted(AuthorizedOperationContext $c, string $instanceKey, ?int $sequenceNo = null): void
    {
        try { $this->dispatch($c, $instanceKey, $sequenceNo); }
        catch (\Throwable) { try { \think\facade\Log::warning('WORKFLOW_NOTIFICATION_PENDING'); } catch (\Throwable) {} }
    }

    private function dispatch(AuthorizedOperationContext $c, string $instanceKey, ?int $sequenceNo): void
    {
        $pdo = Db::connect()->getPdo();
        if (!$pdo instanceof \PDO || $pdo->inTransaction() || $this->notifications === null) { return; }
        [$instance] = $this->instance($c, $instanceKey, 'read', ['peanut.workflow.instance.read']);
        $pending = $this->eventQuery($c, $instance);
        if ($sequenceNo !== null) { $pending->where('sequence_no', $sequenceNo); }
        else { $pending->where(function ($query): void { $query->whereNull('notification_outbox_json')->whereRaw('JSON_LENGTH(notification_outbox_json)=0', [], 'OR')->whereNotNull('notification_last_error', 'OR'); }); }
        foreach ($pending->order('sequence_no')->limit(100)->select() as $event) {
            try { $this->materialize($c, $event); }
            catch (\Throwable) {
                Event::where('tenant_id', $c->tenantContext->tenantId)->where('id', $event['id'])->update(['notification_last_error' => 'NOTIFICATION_DELIVERY_PENDING']);
            }
        }
    }

    public function diagnostics(AuthorizedOperationContext $c, AuthorizedOperationContext $taskContext, string $instanceKey, int $page = 1, int $pageSize = 20, ?AuthorizedOperationContext $taskManage = null): array
    {
        if ($page < 1 || $pageSize < 1 || $pageSize > 100) { throw WorkflowException::definitionInvalid(); }
        [$instance] = $this->instance($c, $instanceKey, 'read', ['peanut.workflow.diagnostics.read']);
        if ($taskContext->tenantContext !== $c->tenantContext || $this->jobs === null) { throw WorkflowException::assignmentDenied(); }
        $items = [];
        $query = $this->eventQuery($c, $instance);
        $total = (int) (clone $query)->count();
        foreach ($query->order('sequence_no')->page($page, $pageSize)->select() as $event) {
            $canRecover = false;
            if ($taskManage !== null && $taskManage->tenantContext === $c->tenantContext) {
                try {
                    $this->instance($c, $instanceKey, 'retry', ['peanut.workflow.diagnostics.retry']);
                    $fact = $event['notification_fact_json'];
                    $this->notifications?->assertPublicationSource($c, new \PeanutAdmin\Modules\Notification\Contract\NotificationPublicationIdentity($fact['producer_key'], $fact['event_key'], $fact['business_key']));
                    $canRecover = $this->notifications !== null;
                } catch (WorkflowException|\PeanutAdmin\Kernel\Authorization\Application\AdminAccessException|\PeanutAdmin\DataPermission\Exception\DataAuthorizationException|\PeanutAdmin\Kernel\Module\ModuleException|\PeanutAdmin\Modules\Notification\Delivery\Application\NotificationException) { $canRecover = false; }
            }
            $outboxes = [];
            foreach (($event['notification_outbox_json'] ?? []) as $outboxKey) {
                $state = $this->notifications?->deliveryState($c, $outboxKey) ?? throw WorkflowException::providerUnavailable();
                $job = $state->jobKey === null ? null : $this->jobs->detail($taskContext, $state->jobKey)->toPublicArray();
                $actions = [];
                if ($canRecover && $job !== null && $job['status'] === 'dead' && in_array($state->status, ['queued', 'retryable'], true)) {
                    try {
                        Db::transaction(fn() => $this->jobs->detailForRecovery($taskManage, $job['job_key'], $job['revision']));
                        $actions = ['retry_original', 'reauthorize'];
                    } catch (\PeanutAdmin\Modules\Task\Job\Application\TaskJobException) { $actions = []; }
                }
                if ($canRecover && $job === null && in_array($state->status, ['pending', 'retryable'], true)) { $actions = ['reauthorize']; }
                $outboxes[] = ['outbox_key' => $state->outboxKey, 'delivery_status' => $state->status, 'task' => $job, 'available_actions' => $actions];
            }
            $items[] = ['sequence_no' => (int) $event['sequence_no'], 'status' => $outboxes === [] ? 'pending_materialization' : 'materialized',
                'last_error' => $event['notification_last_error'], 'outboxes' => $outboxes, 'available_actions' => $canRecover && $outboxes === [] ? ['reauthorize'] : []];
        }
        return ['items' => $items, 'page' => $page, 'page_size' => $pageSize, 'total' => $total];
    }

    public function recover(AuthorizedOperationContext $c, AuthorizedOperationContext $taskContext, string $instanceKey, array $input, string $idempotencyKey): array
    {
        WorkflowGraph::keys($input, ['sequence_no', 'outbox_key', 'expected_job_revision', 'strategy']);
        [$instance] = $this->instance($c, $instanceKey, 'retry', ['peanut.workflow.diagnostics.retry']);
        if ($this->notifications === null || $this->jobs === null || $taskContext->tenantContext !== $c->tenantContext
            || !is_int($input['sequence_no']) || $input['sequence_no'] < 1 || !in_array($input['strategy'], ['retry_original', 'reauthorize'], true)) { throw WorkflowException::assignmentDenied(); }
        $event = Event::scope('tenant', $this->scope($c))->where('instance_id', $instance['id'])->where('sequence_no', $input['sequence_no'])->find() ?? throw WorkflowException::subjectNotFound();
        if (!is_array($event['notification_fact_json'])) { throw WorkflowException::subjectNotFound(); }
        $receipt = $this->transactions->run($c, 'workflow.notification.recover', $instanceKey . ':' . $input['sequence_no'], $input, $idempotencyKey,
            fn() => $this->instance($c, $instanceKey, 'retry', ['peanut.workflow.diagnostics.retry']),
            function () use ($c, $taskContext, $instanceKey, $input, $event): array {
                $fact = $event['notification_fact_json'];
                $this->notifications->assertPublicationSource($c, new \PeanutAdmin\Modules\Notification\Contract\NotificationPublicationIdentity($fact['producer_key'], $fact['event_key'], $fact['business_key']));
                if ($input['outbox_key'] === null) {
                    if ($input['expected_job_revision'] !== null || $input['strategy'] !== 'reauthorize') { throw WorkflowException::definitionInvalid(); }
                } else {
                    if (!is_string($input['outbox_key']) || !in_array($input['outbox_key'], $event['notification_outbox_json'] ?? [], true)
                        || !is_int($input['expected_job_revision']) || $input['expected_job_revision'] < 1) { throw WorkflowException::definitionInvalid(); }
                    $this->notifications->recoverProduced($c, $taskContext, $input['outbox_key'], $input['expected_job_revision'], $input['strategy'], $this->jobs);
                }
                $this->audit->tenantMember($c->tenantContext, 'tenant.workflow.notification_recovery_requested', 'peanut.workflow.diagnostics.retry', 'workflow_instance', $instanceKey,
                    metadata: ['sequence_no' => $input['sequence_no'], 'strategy' => $input['strategy']]);
                return ['sequence_no' => $input['sequence_no'], 'accepted' => true];
            });
        if ($input['outbox_key'] === null) { $this->dispatchCommitted($c, $instanceKey, $input['sequence_no']); }
        return $receipt;
    }

    private function materialize(AuthorizedOperationContext $c, Event $event): void
    {
        if ($this->notifications === null) { throw WorkflowException::providerUnavailable(); }
        $fact = $event['notification_fact_json'];
        $identity = new \PeanutAdmin\Modules\Notification\Contract\NotificationPublicationIdentity($fact['producer_key'], $fact['event_key'], $fact['business_key']);
        $publication = $this->notifications->publishProduced($c, $identity);
        $outboxes = array_map(static fn($record): string => $record->outboxKey, $publication['outbox']);
        Event::where('tenant_id', $c->tenantContext->tenantId)->where('id', $event['id'])->update(['notification_outbox_json' => json_encode($outboxes, JSON_THROW_ON_ERROR)]);
        foreach ($publication['outbox'] as $outbox) {
            if (in_array($outbox->status, ['pending', 'retryable'], true) && $outbox->jobKey === null) { $this->notifications->dispatchProduced($c, $outbox->outboxKey); }
        }
        Event::where('tenant_id', $c->tenantContext->tenantId)->where('id', $event['id'])->update(['notification_last_error' => null]);
    }

    private function eventQuery(AuthorizedOperationContext $c, Instance $instance): \think\db\BaseQuery
    {
        return Event::scope('tenant', $this->scope($c))->where('instance_id', $instance['id'])->whereNotNull('notification_fact_json');
    }

    private function instance(AuthorizedOperationContext $c, string $key, string $operation, array $permissions): array
    {
        $this->lifecycle->assertAvailable($c->tenantContext->tenantId);
        $instance = Instance::scope('tenant', $this->scope($c))->where('instance_key', $key)->find() ?? throw WorkflowException::subjectNotFound();
        $version = Version::scope('tenant', $this->scope($c))->where('definition_id', $instance['definition_id'])->where('version', $instance['definition_version'])->find() ?? throw WorkflowException::subjectNotFound();
        $graph = WorkflowGraph::fromArray($version['graph_json']);
        if (!hash_equals($graph->sha256, $version['graph_sha256'])) { throw WorkflowException::internal(); }
        $this->authorization->authorize($c, $graph->data['subject_resource_key'], $operation === 'read' ? $graph->data['subject_read_operation'] : $operation, $permissions, $instance['subject_key']);
        return [$instance, $graph];
    }

    private function scope(AuthorizedOperationContext $c): TenantScope { return TenantScope::fromTrustedContext($c->tenantContext->tenantId, 'workflow-notification'); }
}
