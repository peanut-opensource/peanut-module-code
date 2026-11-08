<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Integration\Infrastructure\Persistence;

use DateTimeImmutable;
use app\common\execution\CurrentExecutionContext;
use app\common\execution\InstanceExecutionContext;
use app\common\services\audit\AuditContractHost;
use PeanutAdmin\Kernel\Audit\AuditOutcome;
use DateTimeZone;
use PeanutAdmin\Modules\Integration\Contract\IntegrationSecurityRepository;
use PeanutAdmin\IntegrationSecurity\Application\IntegrationSecurityException;
use PeanutAdmin\Modules\Integration\Application\IntegrationSecurityPage;
use PeanutAdmin\Modules\Integration\Application\MachineIdentity;
use PeanutAdmin\Modules\Integration\Application\WebhookAttemptRecord;
use PeanutAdmin\Modules\Integration\Application\WebhookDeliveryRecord;
use PeanutAdmin\Modules\Integration\Application\WebhookEndpoint;
use PeanutAdmin\Modules\Integration\Model\IntegrationMachineIdentityRecord;
use PeanutAdmin\Modules\Integration\Model\IntegrationSecurityEventRecord;
use PeanutAdmin\Modules\Integration\Model\IntegrationWebhookAttemptRecord;
use PeanutAdmin\Modules\Integration\Model\IntegrationWebhookDeliveryRecord;
use PeanutAdmin\Modules\Integration\Model\IntegrationWebhookEndpointRecord;
use PeanutAdmin\Modules\Integration\Webhook\TrustedWebhookEvent;
use PeanutAdmin\Modules\Integration\Webhook\WebhookDelivery;
use PeanutAdmin\Kernel\Auth\TenantContext;
use think\db\Raw;
use think\facade\Db;

final readonly class ThinkPhpIntegrationSecurityRepository implements IntegrationSecurityRepository
{
    public function __construct(
        private ?CurrentExecutionContext $execution = null,
        private ?AuditContractHost $maintenanceAudit = null,
    ) {}

    public function createMachine(
        TenantContext $context,
        string $identityKey,
        string $familyKey,
        string $name,
        array $scopes,
        string $tokenPrefix,
        string $tokenDigest,
        string $tokenLastFour,
        ?DateTimeImmutable $expiresAt,
    ): MachineIdentity {
        return Db::transaction(function () use (
            $context,
            $identityKey,
            $familyKey,
            $name,
            $scopes,
            $tokenPrefix,
            $tokenDigest,
            $tokenLastFour,
            $expiresAt,
        ): MachineIdentity {
            $now = new DateTimeImmutable('now');
            IntegrationMachineIdentityRecord::insert([
                'tenant_id' => $context->tenantId, 'identity_key' => $identityKey, 'family_key' => $familyKey,
                'name' => $name, 'scopes_json' => $this->json($scopes), 'token_prefix' => $tokenPrefix,
                'token_digest' => $tokenDigest, 'token_last_four' => $tokenLastFour,
                'expires_at' => $expiresAt === null ? null : $this->format($expiresAt),
                'created_by_member_id' => $context->memberId,
                'created_at' => $this->format($now), 'updated_at' => $this->format($now),
            ]);
            $this->audit($context, 'tenant.integration.machine_created', 'machine', $identityKey, ['scope_count' => count($scopes)]);

            return $this->machineRow(
                $this->machineByKey($context->tenantId, $identityKey) ?? throw IntegrationSecurityException::machineNotFound(),
            );
        });
    }

    public function machines(int $tenantId): array
    {
        return array_values(array_map($this->machineRow(...), IntegrationMachineIdentityRecord::where('tenant_id', $tenantId)
            ->order('id', 'desc')->select()->toArray()));
    }

    public function machineByDigest(string $tokenDigest): ?array
    {
        $row = IntegrationMachineIdentityRecord::where('token_digest', $tokenDigest)
            ->field('tenant_id,identity_key,scopes_json,status,expires_at')->find()?->toArray();
        if ($row === null) {
            return null;
        }

        return [
            'tenant_id' => (int) $row['tenant_id'], 'identity_key' => (string) $row['identity_key'],
            'scopes' => $this->stringList($row['scopes_json']), 'status' => (string) $row['status'],
            'expires_at' => $row['expires_at'] === null ? null : $this->instant((string) $row['expires_at']),
        ];
    }

    public function touchMachine(string $tokenDigest, DateTimeImmutable $now): void
    {
        IntegrationMachineIdentityRecord::where('token_digest', $tokenDigest)->where('status', 'active')->update([
            'last_used_at' => $this->format($now), 'updated_at' => $this->format($now),
        ]);
    }

    public function rotateMachine(
        TenantContext $context,
        string $identityKey,
        int $expectedRevision,
        string $successorKey,
        string $name,
        array $scopes,
        string $tokenPrefix,
        string $tokenDigest,
        string $tokenLastFour,
        ?DateTimeImmutable $expiresAt,
    ): MachineIdentity {
        return Db::transaction(function () use (
            $context,
            $identityKey,
            $expectedRevision,
            $successorKey,
            $name,
            $scopes,
            $tokenPrefix,
            $tokenDigest,
            $tokenLastFour,
            $expiresAt,
        ): MachineIdentity {
            $current = $this->machineByKey($context->tenantId, $identityKey, true);
            if ($current === null) {
                throw IntegrationSecurityException::machineNotFound();
            }
            if ($current['status'] !== 'active' || (int) $current['revision'] !== $expectedRevision) {
                throw IntegrationSecurityException::conflict();
            }
            $now = new DateTimeImmutable('now');
            if (IntegrationMachineIdentityRecord::where('id', (int) $current['id'])
                ->where('status', 'active')->where('revision', $expectedRevision)->update([
                    'status' => 'rotated', 'rotated_at' => $this->format($now),
                    'revision' => new Raw('revision + 1'), 'updated_at' => $this->format($now),
                ]) !== 1) {
                throw IntegrationSecurityException::conflict();
            }
            IntegrationMachineIdentityRecord::insert([
                'tenant_id' => $context->tenantId, 'identity_key' => $successorKey,
                'family_key' => $current['family_key'], 'name' => $name, 'scopes_json' => $this->json($scopes),
                'token_prefix' => $tokenPrefix, 'token_digest' => $tokenDigest, 'token_last_four' => $tokenLastFour,
                'expires_at' => $expiresAt === null ? null : $this->format($expiresAt),
                'created_by_member_id' => $context->memberId,
                'created_at' => $this->format($now), 'updated_at' => $this->format($now),
            ]);
            $this->audit($context, 'tenant.integration.machine_rotated', 'machine', $identityKey, [
                'successor_hash' => hash('sha256', $successorKey),
            ]);

            return $this->machineRow(
                $this->machineByKey($context->tenantId, $successorKey) ?? throw IntegrationSecurityException::machineNotFound(),
            );
        });
    }

    public function revokeMachine(TenantContext $context, string $identityKey, int $expectedRevision): MachineIdentity
    {
        return Db::transaction(function () use ($context, $identityKey, $expectedRevision): MachineIdentity {
            $row = $this->machineByKey($context->tenantId, $identityKey, true);
            if ($row === null) {
                throw IntegrationSecurityException::machineNotFound();
            }
            if ($row['status'] !== 'active' || (int) $row['revision'] !== $expectedRevision) {
                throw IntegrationSecurityException::conflict();
            }
            $now = new DateTimeImmutable('now');
            if (IntegrationMachineIdentityRecord::where('id', (int) $row['id'])
                ->where('status', 'active')->where('revision', $expectedRevision)->update([
                    'status' => 'revoked', 'revoked_at' => $this->format($now),
                    'revision' => new Raw('revision + 1'), 'updated_at' => $this->format($now),
                ]) !== 1) {
                throw IntegrationSecurityException::conflict();
            }
            $this->audit($context, 'tenant.integration.machine_revoked', 'machine', $identityKey, []);

            return $this->machineRow(
                $this->machineByKey($context->tenantId, $identityKey) ?? throw IntegrationSecurityException::machineNotFound(),
            );
        });
    }

    public function createEndpoint(
        TenantContext $context,
        string $endpointKey,
        string $name,
        string $url,
        array $events,
        string $secretCiphertext,
        string $secretKeyId,
    ): WebhookEndpoint {
        return Db::transaction(function () use (
            $context,
            $endpointKey,
            $name,
            $url,
            $events,
            $secretCiphertext,
            $secretKeyId,
        ): WebhookEndpoint {
            $now = new DateTimeImmutable('now');
            IntegrationWebhookEndpointRecord::insert([
                'tenant_id' => $context->tenantId, 'endpoint_key' => $endpointKey, 'name' => $name,
                'url' => $url, 'events_json' => $this->json($events), 'secret_ciphertext' => $secretCiphertext,
                'secret_key_id' => $secretKeyId, 'created_by_member_id' => $context->memberId,
                'created_at' => $this->format($now), 'updated_at' => $this->format($now),
            ]);
            $this->audit($context, 'tenant.integration.webhook_created', 'webhook', $endpointKey, [
                'event_count' => count($events),
            ]);

            return $this->endpointRow(
                $this->endpointByKey($context->tenantId, $endpointKey) ?? throw IntegrationSecurityException::endpointNotFound(),
            );
        });
    }

    public function endpoints(int $tenantId): array
    {
        return array_values(array_map($this->endpointRow(...), IntegrationWebhookEndpointRecord::where('tenant_id', $tenantId)
            ->order('id', 'desc')->select()->toArray()));
    }

    public function rotateEndpointSecret(
        TenantContext $context,
        string $endpointKey,
        int $expectedRevision,
        string $secretCiphertext,
        string $secretKeyId,
    ): WebhookEndpoint {
        return Db::transaction(function () use (
            $context,
            $endpointKey,
            $expectedRevision,
            $secretCiphertext,
            $secretKeyId,
        ): WebhookEndpoint {
            $row = $this->endpointByKey($context->tenantId, $endpointKey, true);
            if ($row === null) {
                throw IntegrationSecurityException::endpointNotFound();
            }
            if ($row['status'] !== 'active' || (int) $row['revision'] !== $expectedRevision) {
                throw IntegrationSecurityException::conflict();
            }
            if (IntegrationWebhookEndpointRecord::where('id', (int) $row['id'])
                ->where('status', 'active')->where('revision', $expectedRevision)->update([
                    'secret_ciphertext' => $secretCiphertext, 'secret_key_id' => $secretKeyId,
                    'revision' => new Raw('revision + 1'), 'updated_at' => $this->format(new DateTimeImmutable('now')),
                ]) !== 1) {
                throw IntegrationSecurityException::conflict();
            }
            $this->audit($context, 'tenant.integration.webhook_secret_rotated', 'webhook', $endpointKey, []);

            return $this->endpointRow(
                $this->endpointByKey($context->tenantId, $endpointKey) ?? throw IntegrationSecurityException::endpointNotFound(),
            );
        });
    }

    public function disableEndpoint(TenantContext $context, string $endpointKey, int $expectedRevision): WebhookEndpoint
    {
        return Db::transaction(function () use ($context, $endpointKey, $expectedRevision): WebhookEndpoint {
            $row = $this->endpointByKey($context->tenantId, $endpointKey, true);
            if ($row === null) {
                throw IntegrationSecurityException::endpointNotFound();
            }
            if ($row['status'] !== 'active' || (int) $row['revision'] !== $expectedRevision) {
                throw IntegrationSecurityException::conflict();
            }
            $now = $this->format(new DateTimeImmutable('now'));
            if (IntegrationWebhookEndpointRecord::where('id', (int) $row['id'])
                ->where('status', 'active')->where('revision', $expectedRevision)->update([
                    'status' => 'disabled', 'disabled_at' => $now,
                    'revision' => new Raw('revision + 1'), 'updated_at' => $now,
                ]) !== 1) {
                throw IntegrationSecurityException::conflict();
            }
            IntegrationWebhookDeliveryRecord::where('tenant_id', $context->tenantId)
                ->where('endpoint_id', (int) $row['id'])->whereIn('status', ['pending', 'retryable'])->update([
                    'status' => 'permanent_failed', 'last_error_code' => 'WEBHOOK_ENDPOINT_DISABLED', 'updated_at' => $now,
                ]);
            $this->audit($context, 'tenant.integration.webhook_disabled', 'webhook', $endpointKey, []);

            return $this->endpointRow(
                $this->endpointByKey($context->tenantId, $endpointKey) ?? throw IntegrationSecurityException::endpointNotFound(),
            );
        });
    }

    public function activeEndpointKeysForEvent(int $tenantId, string $eventType): array
    {
        $rows = IntegrationWebhookEndpointRecord::where('tenant_id', $tenantId)->where('status', 'active')
            ->order('id')->field('endpoint_key,events_json')->select()->toArray();

        return array_values(array_map(
            static fn(array $row): array => ['endpoint_key' => (string) $row['endpoint_key']],
            array_filter($rows, fn(array $row): bool => in_array($eventType, $this->stringList($row['events_json']), true)),
        ));
    }

    public function enqueueDelivery(
        int $tenantId,
        string $endpointKey,
        TrustedWebhookEvent $event,
        DateTimeImmutable $now,
    ): string {
        $endpoint = $this->endpointByKey($tenantId, $endpointKey, true);
        if ($endpoint === null || $endpoint['status'] !== 'active') {
            throw IntegrationSecurityException::endpointNotFound();
        }
        $payload = $event->canonicalPayload();
        $digest = hash('sha256', $payload);
        $existing = IntegrationWebhookDeliveryRecord::where('tenant_id', $tenantId)
            ->where('endpoint_id', (int) $endpoint['id'])->where('event_key', $event->eventKey)->lock(true)
            ->field('delivery_key,event_type,payload_sha256')->find()?->toArray();
        if ($existing !== null) {
            if (!hash_equals((string) $existing['event_type'], $event->eventType)
                || !hash_equals((string) $existing['payload_sha256'], $digest)) {
                throw IntegrationSecurityException::conflict();
            }

            return (string) $existing['delivery_key'];
        }
        $deliveryKey = 'delivery_' . bin2hex(random_bytes(16));
        IntegrationWebhookDeliveryRecord::insert([
            'tenant_id' => $tenantId, 'endpoint_id' => $endpoint['id'], 'delivery_key' => $deliveryKey,
            'event_key' => $event->eventKey, 'event_type' => $event->eventType, 'payload_json' => $payload,
            'payload_sha256' => $digest, 'available_at' => $this->format($now),
            'payload_expires_at' => $this->format($now->modify('+7 days')),
            'created_at' => $this->format($now), 'updated_at' => $this->format($now),
        ]);

        return $deliveryKey;
    }

    public function claimDelivery(
        int $tenantId,
        string $leaseDigest,
        int $leaseSeconds,
        DateTimeImmutable $now,
    ): ?WebhookDelivery {
        if ($leaseSeconds < 5 || $leaseSeconds > 300 || preg_match('/^[0-9a-f]{64}$/D', $leaseDigest) !== 1) {
            throw IntegrationSecurityException::invalid();
        }

        return Db::transaction(function () use ($tenantId, $leaseDigest, $leaseSeconds, $now): ?WebhookDelivery {
            $expired = IntegrationWebhookDeliveryRecord::where('tenant_id', $tenantId)
                ->where('status', 'delivering')->where('lease_expires_at', '<=', $this->format($now))
                ->order('id')->lock(true)->field('id,attempt_count')->select()->toArray();
            foreach ($expired as $lease) {
                $attempt = (int) $lease['attempt_count'];
                $status = $attempt < 8 ? 'retryable' : 'permanent_failed';
                IntegrationWebhookAttemptRecord::insert([
                    'tenant_id' => $tenantId, 'delivery_id' => $lease['id'], 'attempt_number' => $attempt,
                    'outcome' => $status, 'response_status' => null, 'error_code' => 'WEBHOOK_LEASE_EXPIRED',
                    'duration_ms' => 0, 'attempted_at' => $this->format($now),
                ]);
                IntegrationWebhookDeliveryRecord::where('tenant_id', $tenantId)
                    ->where('id', (int) $lease['id'])->where('status', 'delivering')->update([
                        'status' => $status, 'lease_digest' => null, 'lease_expires_at' => null,
                        'last_error_code' => 'WEBHOOK_LEASE_EXPIRED', 'available_at' => $this->format($now),
                        'updated_at' => $this->format($now),
                    ]);
            }
            $row = IntegrationWebhookDeliveryRecord::alias('delivery')
                ->join('integration_webhook_endpoint endpoint', 'endpoint.tenant_id = delivery.tenant_id AND endpoint.id = delivery.endpoint_id')
                ->where('delivery.tenant_id', $tenantId)->whereIn('delivery.status', ['pending', 'retryable'])
                ->where('delivery.available_at', '<=', $this->format($now))->where('delivery.attempt_count', '<', 8)
                ->whereNotNull('delivery.payload_json')->where('endpoint.status', 'active')
                ->order('delivery.available_at')->order('delivery.id')->lock('FOR UPDATE SKIP LOCKED')
                ->field(['delivery.*', 'endpoint.endpoint_key', 'endpoint.url', 'endpoint.secret_ciphertext', 'endpoint.secret_key_id'])
                ->find()?->toArray();
            if ($row === null) {
                return null;
            }
            $attempt = (int) $row['attempt_count'] + 1;
            if (IntegrationWebhookDeliveryRecord::where('id', (int) $row['id'])
                ->where('tenant_id', $tenantId)->whereIn('status', ['pending', 'retryable'])->update([
                    'status' => 'delivering', 'attempt_count' => $attempt, 'lease_digest' => $leaseDigest,
                    'lease_expires_at' => $this->format($now->modify('+' . $leaseSeconds . ' seconds')),
                    'updated_at' => $this->format($now),
                ]) !== 1) {
                throw IntegrationSecurityException::conflict();
            }

            return new WebhookDelivery(
                (int) $row['id'],
                $tenantId,
                (string) $row['endpoint_key'],
                (string) $row['delivery_key'],
                (string) $row['event_type'],
                $this->jsonText($row['payload_json']),
                (string) $row['payload_sha256'],
                (string) $row['url'],
                (string) $row['secret_ciphertext'],
                (string) $row['secret_key_id'],
                $attempt,
                $leaseDigest,
            );
        });
    }

    public function completeDelivery(WebhookDelivery $delivery, int $statusCode, int $durationMs, DateTimeImmutable $now): void
    {
        $this->finishDelivery($delivery, 'delivered', null, $statusCode, $durationMs, $now, false);
    }

    public function failDelivery(
        WebhookDelivery $delivery,
        string $safeCode,
        bool $retryable,
        ?int $statusCode,
        int $durationMs,
        DateTimeImmutable $now,
    ): void {
        if (preg_match('/^[A-Z][A-Z0-9_]{2,63}$/D', $safeCode) !== 1) {
            throw IntegrationSecurityException::invalid();
        }
        $status = $retryable && $delivery->attemptNumber < 8 ? 'retryable' : 'permanent_failed';
        $this->finishDelivery($delivery, $status, $safeCode, $statusCode, $durationMs, $now, $status === 'retryable');
    }

    private function finishDelivery(
        WebhookDelivery $delivery,
        string $status,
        ?string $errorCode,
        ?int $statusCode,
        int $durationMs,
        DateTimeImmutable $now,
        bool $retryable,
    ): void {
        if ($durationMs < 0 || $durationMs > 30000 || ($statusCode !== null && ($statusCode < 100 || $statusCode > 599))) {
            throw IntegrationSecurityException::invalid();
        }
        Db::transaction(function () use ($delivery, $status, $errorCode, $statusCode, $durationMs, $now, $retryable): void {
            $current = IntegrationWebhookDeliveryRecord::where('tenant_id', $delivery->tenantId)
                ->where('id', $delivery->id)->lock(true)->field('status,attempt_count,lease_digest')->find()?->toArray();
            if ($current === null || $current['status'] !== 'delivering'
                || (int) $current['attempt_count'] !== $delivery->attemptNumber
                || !hash_equals((string) $current['lease_digest'], $delivery->leaseDigest)) {
                throw IntegrationSecurityException::conflict();
            }
            IntegrationWebhookAttemptRecord::insert([
                'tenant_id' => $delivery->tenantId, 'delivery_id' => $delivery->id,
                'attempt_number' => $delivery->attemptNumber, 'outcome' => $status,
                'response_status' => $statusCode, 'error_code' => $errorCode,
                'duration_ms' => $durationMs, 'attempted_at' => $this->format($now),
            ]);
            $available = $retryable
                ? $now->modify('+' . min(300, 5 * (2 ** max(0, $delivery->attemptNumber - 1))) . ' seconds')
                : $now;
            IntegrationWebhookDeliveryRecord::where('tenant_id', $delivery->tenantId)
                ->where('id', $delivery->id)->update([
                    'status' => $status, 'lease_digest' => null, 'lease_expires_at' => null,
                    'last_status_code' => $statusCode, 'last_error_code' => $errorCode,
                    'delivered_at' => $status === 'delivered' ? $this->format($now) : null,
                    'available_at' => $this->format($available), 'updated_at' => $this->format($now),
                ]);
        });
    }

    /**
     * 平台CLI每次仅处理一批：payload、attempt、delivery各不超过limit，不能包在调用方事务里。
     * 子证据多于一批时保留父投递；相同截止条件重复调用安全继续，不加载全量历史ID或正文。
     * @return array{payloads_cleared: int, attempts_deleted: int, deliveries_deleted: int}
     */
    public function purgeExpiredDeliveryData(DateTimeImmutable $payloadCutoff, DateTimeImmutable $evidenceCutoff, int $limit = 100): array
    {
        $context = $this->execution?->current();
        if (!$context instanceof InstanceExecutionContext
            || $context->operation() !== 'console.integration:purge-expired'
            || PHP_SAPI !== 'cli' || $this->maintenanceAudit === null) {
            throw new \DomainException('PLATFORM_MAINTENANCE_CONTEXT_REQUIRED');
        }
        if ($limit < 1 || $limit > 1000) {
            throw new \InvalidArgumentException('INTEGRATION_RETENTION_BATCH_INVALID');
        }
        $connection = Db::connect();
        $pdo = $connection->getPdo();
        $pdo = $pdo instanceof \PDO ? $pdo : $connection->connect();
        if ($pdo->inTransaction()) {
            throw new \DomainException('MAINTENANCE_OUTER_TRANSACTION_FORBIDDEN');
        }
        $metadata = ['payload_cutoff' => $this->format($payloadCutoff), 'evidence_cutoff' => $this->format($evidenceCutoff), 'batch_limit' => $limit];
        try {
            return Db::transaction(function () use ($payloadCutoff, $evidenceCutoff, $limit, $context, $metadata): array {
                $payloadIds = IntegrationWebhookDeliveryRecord::whereNotNull('payload_json')
                    ->where('payload_expires_at', '<=', $this->format($payloadCutoff))
                    ->whereIn('status', ['delivered', 'permanent_failed'])->order('id')->limit($limit)->lock(true)->column('id');
                $payloads = $payloadIds === [] ? 0 : IntegrationWebhookDeliveryRecord::whereIn('id', $payloadIds)->update(['payload_json' => null]);
                $deliveryIds = IntegrationWebhookDeliveryRecord::whereIn('status', ['delivered', 'permanent_failed'])
                    ->where('updated_at', '<=', $this->format($evidenceCutoff))->order('id')->limit($limit)->lock(true)->column('id');
                $attempts = 0;
                $deliveries = 0;
                if ($deliveryIds !== []) {
                    $badLink = IntegrationWebhookAttemptRecord::alias('attempt')
                        ->join('integration_webhook_delivery parent', 'parent.id = attempt.delivery_id')
                        ->whereIn('parent.id', $deliveryIds)->whereRaw('attempt.tenant_id <> parent.tenant_id')
                        ->field('attempt.id')->find();
                    if ($badLink !== null) {
                        throw new \RuntimeException('INTEGRATION_RETENTION_PARENT_SCOPE_MISMATCH');
                    }
                    $attemptIds = IntegrationWebhookAttemptRecord::whereIn('delivery_id', $deliveryIds)
                        ->order('id')->limit($limit)->lock(true)->column('id');
                    $attempts = $attemptIds === [] ? 0 : IntegrationWebhookAttemptRecord::whereIn('id', $attemptIds)->delete();
                    $remainingParents = IntegrationWebhookAttemptRecord::whereIn('delivery_id', $deliveryIds)
                        ->group('delivery_id')->column('delivery_id');
                    $deletable = array_values(array_diff($deliveryIds, $remainingParents));
                    $deliveries = $deletable === [] ? 0 : IntegrationWebhookDeliveryRecord::whereIn('id', $deletable)->delete();
                }
                $result = ['payloads_cleared' => $payloads, 'attempts_deleted' => $attempts, 'deliveries_deleted' => $deliveries];
                $this->maintenanceAudit->recordPlatform(
                    'platform.integration.delivery_data_purged',
                    'integration.purge_expired_delivery_data',
                    $context->requestId(),
                    null,
                    null,
                    [...$metadata, ...$result],
                );
                return $result;
            });
        } catch (\Throwable $failure) {
            try {
                $this->maintenanceAudit->recordPlatform(
                    'platform.integration.delivery_purge_failed',
                    'integration.purge_expired_delivery_data',
                    $context->requestId(),
                    null,
                    null,
                    [...$metadata, 'payloads_cleared' => 0, 'attempts_deleted' => 0, 'deliveries_deleted' => 0],
                    AuditOutcome::Error,
                    'INTEGRATION_RETENTION_FAILED',
                );
            } catch (\Throwable) {
                throw new \RuntimeException('PLATFORM_MAINTENANCE_FAILURE_AUDIT_FAILED', 0, $failure);
            }
            throw $failure;
        }
    }

    public function deliveryRecords(int $tenantId, int $page, int $pageSize): IntegrationSecurityPage
    {
        // 联表后两侧都有 tenant_id；限定主表并让计数与数据读取共用同一租户关联。
        $query = IntegrationWebhookDeliveryRecord::alias('delivery')
            ->where('delivery.tenant_id', $tenantId)
            ->join('integration_webhook_endpoint endpoint', 'endpoint.tenant_id = delivery.tenant_id AND endpoint.id = delivery.endpoint_id');
        $total = (int) (clone $query)->count();
        $rows = $query->order('delivery.created_at', 'desc')->order('delivery.id', 'desc')
            ->page($page, $pageSize)->field([
                'delivery.delivery_key', 'endpoint.endpoint_key', 'delivery.event_type', 'delivery.status',
                'delivery.attempt_count', 'delivery.last_status_code', 'delivery.last_error_code',
                'delivery.created_at', 'delivery.updated_at', 'delivery.delivered_at',
            ])->select()->toArray();
        $items = array_values(array_map(fn(array $row): WebhookDeliveryRecord => new WebhookDeliveryRecord(
            (string) $row['delivery_key'],
            (string) $row['endpoint_key'],
            (string) $row['event_type'],
            (string) $row['status'],
            (int) $row['attempt_count'],
            $row['last_status_code'] === null ? null : (int) $row['last_status_code'],
            $row['last_error_code'] === null ? null : (string) $row['last_error_code'],
            $this->instant((string) $row['created_at']),
            $this->instant((string) $row['updated_at']),
            $row['delivered_at'] === null ? null : $this->instant((string) $row['delivered_at']),
        ), $rows));

        return new IntegrationSecurityPage($items, $page, $pageSize, $total);
    }

    public function deliveryAttemptRecords(
        int $tenantId,
        string $deliveryKey,
        int $page,
        int $pageSize,
    ): IntegrationSecurityPage {
        $deliveryId = IntegrationWebhookDeliveryRecord::where('tenant_id', $tenantId)
            ->where('delivery_key', $deliveryKey)->value('id');
        if ($deliveryId === null) {
            return new IntegrationSecurityPage([], $page, $pageSize, 0);
        }
        $query = IntegrationWebhookAttemptRecord::where('tenant_id', $tenantId)->where('delivery_id', $deliveryId);
        $total = (int) (clone $query)->count();
        $rows = $query->order('attempt_number', 'desc')->page($page, $pageSize)
            ->field('attempt_number,outcome,response_status,error_code,duration_ms,attempted_at')->select()->toArray();
        $items = array_values(array_map(fn(array $row): WebhookAttemptRecord => new WebhookAttemptRecord(
            (int) $row['attempt_number'],
            (string) $row['outcome'],
            $row['response_status'] === null ? null : (int) $row['response_status'],
            $row['error_code'] === null ? null : (string) $row['error_code'],
            (int) $row['duration_ms'],
            $this->instant((string) $row['attempted_at']),
        ), $rows));

        return new IntegrationSecurityPage($items, $page, $pageSize, $total);
    }

    /** @return array<string, mixed>|null */
    private function machineByKey(int $tenantId, string $identityKey, bool $lock = false): ?array
    {
        $query = IntegrationMachineIdentityRecord::where('tenant_id', $tenantId)->where('identity_key', $identityKey);
        if ($lock) {
            $query->lock(true);
        }

        return $query->find()?->toArray();
    }

    /** @return array<string, mixed>|null */
    private function endpointByKey(int $tenantId, string $endpointKey, bool $lock = false): ?array
    {
        $query = IntegrationWebhookEndpointRecord::where('tenant_id', $tenantId)->where('endpoint_key', $endpointKey);
        if ($lock) {
            $query->lock(true);
        }

        return $query->find()?->toArray();
    }

    /** @param array<string, mixed> $row */
    private function machineRow(array $row): MachineIdentity
    {
        return new MachineIdentity(
            (string) $row['identity_key'],
            (string) $row['name'],
            $this->stringList($row['scopes_json']),
            (string) $row['status'],
            (string) $row['token_prefix'],
            (string) $row['token_last_four'],
            $row['expires_at'] === null ? null : $this->instant((string) $row['expires_at']),
            $row['last_used_at'] === null ? null : $this->instant((string) $row['last_used_at']),
            (int) $row['revision'],
            $this->instant((string) $row['created_at']),
        );
    }

    /** @param array<string, mixed> $row */
    private function endpointRow(array $row): WebhookEndpoint
    {
        return new WebhookEndpoint(
            (string) $row['endpoint_key'],
            (string) $row['name'],
            (string) $row['url'],
            $this->stringList($row['events_json']),
            (string) $row['status'],
            (int) $row['revision'],
            $this->instant((string) $row['created_at']),
        );
    }

    /** @param array<string, scalar|null> $metadata */
    private function audit(
        TenantContext $context,
        string $eventKey,
        string $targetType,
        string $targetKey,
        array $metadata,
    ): void {
        if (count($metadata) > 8) {
            throw IntegrationSecurityException::invalid();
        }
        IntegrationSecurityEventRecord::insert([
            'tenant_id' => $context->tenantId, 'event_key' => $eventKey,
            'actor_member_id' => $context->memberId, 'target_type' => $targetType,
            'target_key_hash' => hash('sha256', $targetKey), 'metadata_json' => $this->json($metadata),
            'request_id_hash' => hash('sha256', $context->requestId),
            'occurred_at' => $this->format(new DateTimeImmutable('now')),
        ]);
    }

    /** @return list<string> */
    private function stringList(mixed $json): array
    {
        $decoded = is_array($json)
            ? $json
            : json_decode($this->jsonText($json), true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($decoded) || !array_is_list($decoded)) {
            throw IntegrationSecurityException::invalid();
        }
        foreach ($decoded as $item) {
            if (!is_string($item)) {
                throw IntegrationSecurityException::invalid();
            }
        }

        return $decoded;
    }

    private function jsonText(mixed $json): string
    {
        if (is_array($json)) {
            return $this->json($json);
        }
        if (!is_string($json)) {
            throw IntegrationSecurityException::invalid();
        }
        json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        return $json;
    }

    private function json(mixed $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function format(DateTimeImmutable $value): string
    {
        return $value->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.v');
    }

    private function instant(string $value): string
    {
        return (new DateTimeImmutable($value, new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v\Z');
    }
}
