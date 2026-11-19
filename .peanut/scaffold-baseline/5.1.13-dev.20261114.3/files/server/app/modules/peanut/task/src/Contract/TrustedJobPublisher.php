<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Task\Contract;

use JsonException;
use PeanutAdmin\Kernel\Async\TrustedEnvelopeCodec;
use PeanutAdmin\Kernel\Context\AuthorizedOperationContext;
use think\facade\Db;
use PeanutAdmin\Modules\Task\Job\Application\TaskJobException;
use PeanutAdmin\Modules\Task\Job\Persistence\TaskJobStore;
use PeanutAdmin\Modules\Task\Job\Submission\TaskSubmissionRegistry;
use PeanutAdmin\DataPermission\Catalog\ResourceOperationCatalog;
use PeanutAdmin\Modules\Identity\Module\TenantEntitlementService;

final readonly class TrustedJobPublisher
{
    public function __construct(
        private TaskJobStore $repository,
        private TaskSubmissionRegistry $submissions,
        private TrustedEnvelopeCodec $envelopes,
        private ResourceOperationCatalog $operations,
        private TenantEntitlementService $entitlements,
        private ?string $negativeSigningKey = null,
    ) {}

    public function publishNegativeFact(array $fact): JobRecord
    {
        $type = \PeanutAdmin\Modules\Task\Job\Execution\NegativeFactJobEnvelope::TASK;
        $provider = $this->submissions->require($type);
        $pdo = Db::connect()->getPdo();
        if (!$provider instanceof NegativeFactTaskHandler || !$pdo instanceof \PDO || !$pdo->inTransaction()
            || $this->negativeSigningKey === null || (new \PeanutAdmin\Modules\Task\Job\Persistence\Model\TaskJobRecord())->db()->getConnection()->getPdo() !== $pdo) {
            throw TaskJobException::denied();
        }
        $submission = $provider->negativeSubmission($fact);
        if ($submission->handlerKey !== $type || $submission->maxAttempts !== 3 || $submission->initialDelaySeconds !== 0) {
            throw TaskJobException::denied();
        }
        $jobKey = 'job_' . bin2hex(random_bytes(16));
        $payload = $this->canonicalJson($submission->payload);
        $envelope = \PeanutAdmin\Modules\Task\Job\Execution\NegativeFactJobEnvelope::issue($fact['tenant_id'], $fact['fact_id'], $fact['fact_sha256'], $jobKey, $this->negativeSigningKey);
        $result = $this->repository->enqueue(
            $fact['tenant_id'],
            null,
            $jobKey,
            $type,
            $type,
            $payload,
            $envelope,
            hash('sha256', $fact['fact_id']),
            hash('sha256', $payload),
            3,
            0,
            'identity_negative_fact',
            $fact['fact_id'],
        );
        if (Db::connect()->getPdo() !== $pdo || !$pdo->inTransaction()) {
            throw TaskJobException::denied();
        }
        return $result;
    }

    public function negativeStatus(int $tenantId, string $factId): array
    {
        return $this->repository->negativeStatus($tenantId, $factId);
    }
    public function retryNegative(int $tenantId, string $factId): void
    {
        $this->repository->retryNegative($tenantId, $factId);
    }

    /** Descriptor check only; grants no permission and creates no task/session record. */
    public function assertRegistered(string $taskType, string $resourceKey, string $operation): void
    {
        $provider = $this->submissions->require($taskType);
        if (!hash_equals($provider->resourceKey(), $resourceKey) || !hash_equals($provider->operation(), $operation)) {
            throw TaskJobException::denied();
        }
    }

    /** @param array<string, mixed> $input */
    public function publish(
        AuthorizedOperationContext $context,
        string $taskType,
        array $input,
        string $idempotencyKey,
    ): JobRecord {
        if (strlen($idempotencyKey) < 8 || strlen($idempotencyKey) > 200 || preg_match('/^[\x21-\x7e]+$/D', $idempotencyKey) !== 1) {
            throw TaskJobException::invalid();
        }
        $provider = $this->submissions->require($taskType);
        if (!hash_equals($provider->resourceKey(), $context->resourceKey)
            || !hash_equals($provider->operation(), $context->operation)
        ) {
            throw TaskJobException::denied();
        }
        $moduleKey = $provider instanceof TaskWorkerDefinition
            ? $provider->ownerModuleKey()
            : $this->operations->find($context->resourceKey, $context->operation)?->moduleKey;
        if ($moduleKey === null || ($moduleKey !== 'core'
            && !in_array($moduleKey, $this->entitlements->moduleKeys($context->tenantContext->tenantId), true))) {
            throw TaskJobException::denied();
        }
        $submission = $provider->build($context, $input);
        if (preg_match('/^[a-z][a-z0-9]*(?:[.-][a-z0-9]+)*$/D', $submission->handlerKey) !== 1
            || $submission->maxAttempts < 1 || $submission->maxAttempts > 10
            || $submission->initialDelaySeconds < 0 || $submission->initialDelaySeconds > 86400
        ) {
            throw TaskJobException::invalid();
        }
        $payload = $this->canonicalJson($submission->payload);
        if (strlen($payload) > 65535) {
            throw TaskJobException::invalid();
        }
        $jobKey = 'job_' . bin2hex(random_bytes(16));
        $requestHash = hash('sha256', $this->canonicalJson([
            'task_type' => $taskType,
            'handler_key' => $submission->handlerKey,
            'payload' => $submission->payload,
            'max_attempts' => $submission->maxAttempts,
            'initial_delay_seconds' => $submission->initialDelaySeconds,
        ]));
        $job = Db::transaction(
            fn(): JobRecord => $this->repository->enqueue(
                $context->tenantContext->tenantId,
                $context->tenantContext->memberId,
                $jobKey,
                $taskType,
                $submission->handlerKey,
                $payload,
                $this->envelopes->issue($context, $jobKey, $context->tenantContext->requestId),
                hash('sha256', $idempotencyKey),
                $requestHash,
                $submission->maxAttempts,
                $submission->initialDelaySeconds,
            ),
        );
        return $job;
    }

    /** @throws JsonException */
    private function canonicalJson(mixed $value): string
    {
        return json_encode($this->normalize($value, 0), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function normalize(mixed $value, int $depth): mixed
    {
        if ($depth > 16) {
            throw TaskJobException::invalid();
        }
        if (is_array($value)) {
            if (!array_is_list($value)) {
                ksort($value, SORT_STRING);
            }
            foreach ($value as $key => $item) {
                $value[$key] = $this->normalize($item, $depth + 1);
            }
            return $value;
        }
        if ($value === null || is_bool($value) || is_int($value) || is_string($value)) {
            return $value;
        }
        throw TaskJobException::invalid();
    }
}
