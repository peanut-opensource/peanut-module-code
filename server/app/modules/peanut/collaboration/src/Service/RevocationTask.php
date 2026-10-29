<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Collaboration\Service;

use app\common\execution\CurrentExecutionContext;
use PeanutAdmin\Kernel\Async\VerifiedJobEnvelope;
use PeanutAdmin\Kernel\Context\AuthorizedOperationContext;
use PeanutAdmin\Modules\Collaboration\Model\CollaborationConnection;
use PeanutAdmin\Modules\Collaboration\Model\CollaborationRoom;
use PeanutAdmin\Modules\Collaboration\Protocol\TicketIdentity;
use PeanutAdmin\Modules\Identity\Contract\RevocationFacts;
use PeanutAdmin\Modules\Identity\Contract\RevocationFactDelivery;
use PeanutAdmin\Modules\Identity\Contract\TenantSessionAccess;
use PeanutAdmin\Modules\Identity\Contract\TenantSessionAccessException;
use PeanutAdmin\Modules\Task\Contract\JobExecution;
use PeanutAdmin\Modules\Task\Contract\NegativeFactTaskHandler;
use PeanutAdmin\Modules\Task\Contract\RetryableTaskException;
use PeanutAdmin\Modules\Task\Contract\TaskJobRuntime;
use PeanutAdmin\Modules\Task\Contract\TaskSubmission;
use PeanutAdmin\Modules\Task\Contract\TaskSubmissionProvider;
use PeanutAdmin\Modules\Task\Contract\TaskWorkerDefinition;
use PeanutAdmin\Modules\Task\Job\Execution\NegativeFactJobEnvelope;
use think\facade\Db;

/** The single registered negative-fact definition/submission/handler, using normal Task registries. */
final readonly class RevocationTask implements TaskWorkerDefinition, \PeanutAdmin\Modules\Task\Contract\TaskWorkerContributor, TaskSubmissionProvider, NegativeFactTaskHandler, RevocationFactDelivery
{
    public function __construct(
        private RevocationFacts $facts,
        private \Closure $taskRuntime,
        private TenantSessionAccess $sessions,
        private AccessInvalidationClient $control,
        private CurrentExecutionContext $execution,
        private array $configuration,
        private string $edition,
        private \PeanutAdmin\Modules\Document\Contract\DocumentCollaborativeWrites $documents,
    ) {}

    public function ownerModuleKey(): string
    {
        return 'peanut.collaboration';
    }
    public function resourceKey(): string
    {
        return NegativeFactJobEnvelope::RESOURCE;
    }
    public function operation(): string
    {
        return NegativeFactJobEnvelope::PURPOSE;
    }
    public function taskType(): string
    {
        return NegativeFactJobEnvelope::TASK;
    }
    public function key(): string
    {
        return NegativeFactJobEnvelope::TASK;
    }
    public function handlers(): array
    {
        return [$this];
    }
    public function taskWorkerDefinitions(): array
    {
        return [self::class];
    }
    public function reauthorize(VerifiedJobEnvelope $envelope): AuthorizedOperationContext
    {
        throw new \DomainException('REVOCATION_MEMBER_ENVELOPE_DENIED');
    }
    public function build(AuthorizedOperationContext $context, array $input): TaskSubmission
    {
        throw new \DomainException('REVOCATION_MEMBER_SUBMISSION_DENIED');
    }
    public function handle(AuthorizedOperationContext $context, JobExecution $execution): void
    {
        throw new \DomainException('REVOCATION_MEMBER_HANDLER_DENIED');
    }

    public function negativeSubmission(array $fact): TaskSubmission
    {
        $known = $this->facts->revocationFact($fact['tenant_id'], $fact['fact_id'], $fact['fact_sha256']);
        if (self::canonical($known) !== self::canonical($fact)) {
            throw new \DomainException('REVOCATION_PRODUCER_FACT_MISMATCH');
        }
        return new TaskSubmission($this->key(), ['fact_id' => $fact['fact_id'], 'fact_sha256' => $fact['fact_sha256'],
            'purpose' => NegativeFactJobEnvelope::PURPOSE, 'tenant_id' => $fact['tenant_id']], 3);
    }

    public function enqueueRevocation(array $fact): void
    {
        // The immutable audit fact survives optional absence; explicit discovery can enqueue it later.
        $tasks = ($this->taskRuntime)();
        if ($tasks === null) {
            return;
        }
        $tasks->publisher($this)->publishNegativeFact($fact);
    }

    public function reconcileRevocation(array $fact, bool $repair = false): array
    {
        $context = $this->execution->current();
        if (PHP_SAPI !== 'cli' || !$context instanceof \app\common\execution\InstanceExecutionContext
            || $context->operation() !== 'console.identity:revocations') {
            throw new \DomainException('REVOCATION_DISCOVERY_DENIED');
        }
        $tasks = ($this->taskRuntime)();
        if ($tasks === null) {
            return ['status' => 'consumer_unavailable', 'notification_overdue_candidate' => true];
        }
        $fact = $this->facts->revocationFact($fact['tenant_id'], $fact['fact_id'], $fact['fact_sha256']);
        $publisher = $tasks->publisher($this);
        $state = $publisher->negativeStatus($fact['tenant_id'], $fact['fact_id']);
        if ($state['status'] === 'not_queued') {
            Db::transaction(fn() => $publisher->publishNegativeFact($fact));
            $state = $publisher->negativeStatus($fact['tenant_id'], $fact['fact_id']);
        }
        $notApplicable = $state['status'] === 'succeeded'
            && ($state['delivery_outcome'] ?? null) === 'not_applicable'
            && !$this->hasCurrentGrant($fact);
        // occurred_at is pre-commit; this is a conservative candidate, never a measured T0 guarantee.
        $currentHolder = null;
        if (!$notApplicable) {
            try {
                $currentHolder = $this->currentHolder();
            } catch (RetryableTaskException) {
                $currentHolder = null;
            }
        }
        $holderConfirmed = $state['status'] === 'succeeded' && $currentHolder !== null && ($state['ack_holder_key'] ?? null) === $currentHolder;
        $confirmed = $notApplicable || $holderConfirmed;
        if ($repair && !$confirmed && in_array($state['status'], ['dead', 'succeeded'], true)) {
            Db::transaction(fn() => $publisher->retryNegative($fact['tenant_id'], $fact['fact_id']));
            $state = $publisher->negativeStatus($fact['tenant_id'], $fact['fact_id']);
        }
        $state['current_holder_confirmed'] = $holderConfirmed;
        $state['delivery_confirmed'] = $confirmed;
        $state['notification_overdue_candidate'] = !$confirmed
            && time() - strtotime($fact['occurred_at'] . ' UTC') >= 30;
        return $state;
    }

    public function validateNegativeFact(NegativeFactJobEnvelope $envelope): void
    {
        $context = $this->execution->systemExecution();
        if ($context->operation() !== NegativeFactJobEnvelope::PURPOSE || $context->tenantId() !== $envelope->tenantId
            || $context->metadata->jobKey !== $envelope->jobKey || $context->metadata->handlerKey !== $this->key()) {
            throw new \DomainException('REVOCATION_WORKER_PURPOSE_MISMATCH');
        }
        $this->facts->revocationFact($envelope->tenantId, $envelope->factId, $envelope->factHash);
    }

    public function handleNegativeFact(NegativeFactJobEnvelope $envelope, JobExecution $execution): void
    {
        $fact = $this->facts->revocationFact($envelope->tenantId, $envelope->factId, $envelope->factHash);
        // PHP and Node both reject expired room/connection grants; no holder ACK is needed
        // when this fact has no remaining grant that can authorize reads or writes.
        if (!$this->hasCurrentGrant($fact)) {
            $reference = hash('sha256', 'revocation-not-applicable:' . $envelope->factId);
            if ($execution->negativeState($reference) === null) {
                $execution->recordNegativeState($reference, [
                    'outcome' => 'not_applicable', 'reason' => 'no_current_grant', 'batches' => 0,
                    'fact_id' => $envelope->factId, 'fact_sha256' => $envelope->factHash,
                ]);
            }
            return;
        }
        $holder = $this->currentHolder();
        $planKey = hash('sha256', 'revocation-plan:' . $envelope->factId . ':' . $holder);
        $plan = $execution->negativeState($planKey);
        if ($plan === null) {
            $plan = ['holder_key' => $holder, 'high_water' => (int) $this->connections($fact)->max('connection.id')];
            $execution->recordNegativeState($planKey, $plan);
        }
        $cursor = 0;
        $batch = 0;
        $dispatched = 0;
        do {
            $execution->checkpoint();
            if ($this->currentHolder() !== $holder) {
                throw new RetryableTaskException('REVOCATION_HOLDER_CHANGED');
            }
            $batchKey = hash('sha256', $planKey . ':batch:' . $batch);
            $scope = $execution->negativeState($batchKey);
            if ($scope === null) {
                $rows = $this->connections($fact)->where('connection.id', '>', $cursor)->where('connection.id', '<=', $plan['high_water'])
                    ->field('connection.id,connection.connection_key,connection.lease_epoch,connection.authorization_revision,connection.mode,ticket.document_id,ticket.issued_identity_json,room.room_key,room.protocol_version')
                    ->order('connection.id')->limit(100)->select()->toArray();
                $targets = [];
                foreach ($rows as $row) {
                    $identity = TicketIdentity::fromLedger(NativeCollaborationProvider::json($row['issued_identity_json']));
                    if ($identity->tenantId !== $fact['tenant_id'] || $identity->memberId !== $fact['member_id']) {
                        throw new \DomainException('REVOCATION_LEDGER_SCOPE_INVALID');
                    }
                    if ($fact['session_fingerprint'] !== null && !hash_equals($fact['session_fingerprint'], hash('sha256', $identity->sessionKey))) {
                        continue;
                    }
                    if ((int) $row['protocol_version'] !== 2 || !in_array($row['mode'], ['read', 'write'], true)) {
                        throw new RetryableTaskException('REVOCATION_GRANT_VERSION_UNKNOWN');
                    }
                    $target = ['connection_key' => $row['connection_key'], 'room_key' => $row['room_key'], 'lease_epoch' => (int) $row['lease_epoch'],
                        'member_id' => $identity->memberId, 'authorization_revision' => $row['authorization_revision']];
                    foreach (['connection_key', 'room_key', 'authorization_revision'] as $key) {
                        if (!is_string($target[$key]) || preg_match('/^[0-9a-f]{64}$/D', $target[$key]) !== 1) {
                            throw new \DomainException('REVOCATION_LEDGER_SCOPE_INVALID');
                        }
                    }
                    if ($target['lease_epoch'] < 1 || $target['lease_epoch'] > 9007199254740991 || $identity->memberId > 9007199254740991) {
                        throw new \DomainException('REVOCATION_LEDGER_SCOPE_INVALID');
                    }
                    try {
                        $current = $identity->revalidate($this->sessions, $envelope->jobKey);
                        $stale = $current->authorizationRevision !== $identity->authorizationRevision;
                    } catch (TenantSessionAccessException) {
                        $stale = true;
                    }
                    if (!$stale) {
                        $execution->assertLeaseOwned();
                        $decision = $this->documents->compareRevocationGrant($current, (int) $row['document_id'], $row['mode'], $row['authorization_revision'], $fact['fact_id'], $fact['fact_sha256']);
                        $execution->assertLeaseOwned();
                        if (($decision['status'] ?? null) === 'OLD_STALE') {
                            $stale = true;
                        } elseif (($decision['status'] ?? null) !== 'CURRENT_FRESH') {
                            throw new RetryableTaskException('REVOCATION_GRANT_AUTHORITY_UNKNOWN');
                        }
                    }
                    if (!$stale) {
                        continue;
                    } // Never close a subsequently freshly authorized grant.
                    $targets[] = $target;
                }
                $body = ['protocol_version' => 1, 'action' => 'access.invalidate', 'fact_id' => $fact['fact_id'], 'fact_sha256' => $fact['fact_sha256'],
                    'tenant_id' => $fact['tenant_id'], 'subject' => ['kind' => $fact['subject_kind'], 'id' => $fact['subject_id'], 'member_id' => $fact['member_id']],
                    'negative_action' => 'invalidate_old_grant', 'revision_reference_sha256' => hash('sha256', self::canonical([$fact['before'], $fact['after']])),
                    'operation_id' => $envelope->jobKey . ':' . $batch, 'instance_id' => $this->configuration['instance_id'],
                    'service_instance_id' => $this->configuration['service_instance_id'], 'holder_key' => $holder, 'targets' => $targets];
                $body['scope_digest'] = hash('sha256', self::canonical($body));
                $scope = ['body' => $body, 'next_cursor' => $rows === [] ? $cursor : (int) $rows[array_key_last($rows)]['id'], 'done' => count($rows) < 100];
                $execution->recordNegativeState($batchKey, $scope);
            }
            $ackKey = hash('sha256', $batchKey . ':ack');
            if ($execution->negativeState($ackKey) === null) {
                $execution->checkpoint();
                $this->assertScope($fact, $scope['body']);
                $ack = $this->control->invalidate($scope['body']);
                $execution->assertLeaseOwned();
                $this->assertScope($fact, $scope['body']);
                $execution->recordNegativeState($ackKey, $ack);
                $dispatched++;
            }
            $cursor = $scope['next_cursor'];
            $batch++;
            if ($dispatched >= 100 && !$scope['done']) {
                throw new RetryableTaskException('REVOCATION_BATCH_LIMIT');
            }
        } while (!$scope['done']);
        $execution->recordNegativeState(hash('sha256', $planKey . ':complete'), ['holder_key' => $holder, 'batches' => $batch, 'high_water' => $plan['high_water']]);
    }

    private function connections(array $fact): \think\db\BaseQuery
    {
        $prefix = (string) Db::connect()->getConfig('prefix');
        return CollaborationConnection::alias('connection')->join($prefix . 'collaboration_ticket ticket', 'ticket.id=connection.ticket_id AND ticket.tenant_id=connection.tenant_id')
            ->join($prefix . 'collaboration_room room', 'room.id=connection.room_id AND room.tenant_id=connection.tenant_id')
            ->where('connection.tenant_id', $fact['tenant_id'])->where('ticket.instance_id', $this->configuration['instance_id'])
            ->where('ticket.edition', $this->edition)->whereRaw("JSON_EXTRACT(ticket.issued_identity_json, '$.member_id') = ?", [$fact['member_id']]);
    }

    private function hasCurrentGrant(array $fact): bool
    {
        return $this->connections($fact)->where('connection.status', 'active')
            ->where('connection.lease_expires_at', '>', gmdate('Y-m-d H:i:s'))
            ->where('room.status', '<>', 'closed')->where('room.lease_expires_at', '>', gmdate('Y-m-d H:i:s'))
            ->count() > 0;
    }

    private function currentHolder(): string
    {
        // Signed room admission, rather than a global deployment phase, records custody.
        // Instance discovery deliberately bypasses Tenant scope but remains application/service-bound.
        $holders = CollaborationRoom::withoutTenantScope()->where('instance_id', $this->configuration['instance_id'])
            ->where('edition', $this->edition)->where('service_instance_id', $this->configuration['service_instance_id'])
            ->where('status', '<>', 'closed')->where('lease_expires_at', '>', gmdate('Y-m-d H:i:s'))
            ->group('holder_key')->limit(2)->column('holder_key');
        if (count($holders) !== 1) {
            throw new RetryableTaskException('REVOCATION_HOLDER_UNAVAILABLE');
        }
        $holder = reset($holders);
        if (!is_string($holder) || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $holder) !== 1
        ) {
            throw new RetryableTaskException('REVOCATION_HOLDER_UNAVAILABLE');
        }
        return $holder;
    }

    private function assertScope(array $fact, array $body): void
    {
        $this->facts->revocationFact($fact['tenant_id'], $fact['fact_id'], $fact['fact_sha256']);
        if ($this->currentHolder() !== $body['holder_key'] || $body['tenant_id'] !== $fact['tenant_id']
            || $body['fact_id'] !== $fact['fact_id'] || $body['fact_sha256'] !== $fact['fact_sha256']) {
            throw new RetryableTaskException('REVOCATION_HOLDER_CHANGED');
        }
        foreach ($body['targets'] as $target) {
            $row = $this->connections($fact)->where('connection.connection_key', $target['connection_key'])->field('room.room_key,ticket.issued_identity_json')->find();
            if ($row !== null && $row['room_key'] !== $target['room_key']) {
                throw new \DomainException('REVOCATION_SCOPE_CHANGED');
            }
            // Node matches the complete old grant tuple. A renewed/new grant is not that tuple.
        }
    }

    private static function canonical(array $value): string
    {
        $normalize = static function (mixed $v) use (&$normalize): mixed {
            if (is_array($v)) {
                if (!array_is_list($v)) {
                    ksort($v, SORT_STRING);
                } foreach ($v as &$item) {
                    $item = $normalize($item);
                }
            }
            return $v;
        };
        return json_encode($normalize($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }
}
