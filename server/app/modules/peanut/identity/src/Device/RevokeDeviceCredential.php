<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Identity\Device;

use app\common\contract\idempotency\IdempotencyCommand;
use app\common\contract\idempotency\IdempotencyReceipt;
use app\common\contract\idempotency\IdempotentCommandExecutor;
use app\common\exception\BusinessException;
use DateTimeImmutable;
use DateTimeZone;
use PeanutAdmin\Kernel\Api\ApiException;
use PeanutAdmin\Kernel\Audit\AuditWriter;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Modules\Identity\Contract\DeviceCredentialRevocations;
use PeanutAdmin\Modules\Identity\Contract\Dto\RevokeDeviceCredentialInput;
use think\db\exception\PDOException;
use think\facade\Db;

final readonly class RevokeDeviceCredential implements DeviceCredentialRevocations
{
    private const PERMISSION = 'peanut.identity.device-credential.revoke';

    public function __construct(
        private DeviceCredentialAccess $access,
        private IdempotentCommandExecutor $commands,
        private AuditWriter $audit,
    ) {}

    public function revoke(TenantContext $actor, string $credentialKey, #[\SensitiveParameter] RevokeDeviceCredentialInput $input): array
    {
        $this->validate($credentialKey, $input);
        $pdo = Db::connect()->getPdo();
        if ($pdo !== false && $pdo->inTransaction()) {
            throw new BusinessException('IDENTITY_DEVICE_INVARIANT', 422, '设备凭据撤销根入口需要独立业务事务。');
        }
        $this->access->assert($actor, self::PERMISSION);
        $hash = DeviceCredentialRevocationEvidence::inputHash($actor, $credentialKey, $input);
        try {
            return Db::transaction(fn(): array => $this->revokeInTransaction($actor, $credentialKey, $input));
        } catch (BusinessException | PDOException $error) {
            if ($error instanceof PDOException && !self::sourceRace($error)) {
                throw $error;
            }
            return Db::transaction(function () use ($actor, $input, $hash, $error): array {
                $this->access->assert($actor, self::PERMISSION);
                $source = $this->source($actor, $input->commandKey);
                if ($source !== null) {
                    return $this->original($source, $hash);
                }
                if ($error instanceof BusinessException) {
                    throw $error;
                }
                return self::pending($actor);
            });
        }
    }

    public function revokeInTransaction(
        TenantContext $actor,
        string $credentialKey,
        #[\SensitiveParameter]
        RevokeDeviceCredentialInput $input,
    ): array {
        $this->validate($credentialKey, $input);
        $pdo = Db::connect()->getPdo();
        if ($pdo === false || !$pdo->inTransaction()) {
            throw new BusinessException('IDENTITY_DEVICE_INVARIANT', 422, '设备凭据撤销参与入口需要调用方业务事务。');
        }
        $this->access->assert($actor, self::PERMISSION, true);
        $hash = DeviceCredentialRevocationEvidence::inputHash($actor, $credentialKey, $input);
        $source = $this->source($actor, $input->commandKey);
        if ($source !== null) {
            return $this->original($source, $hash);
        }
        $credential = Db::table('pa_device_credential')->where([
            'tenant_id' => $actor->tenantId, 'credential_key' => $credentialKey,
        ])->lock(true)->find();
        if ($credential === null) {
            throw BusinessException::notFound('IDENTITY_DEVICE_NOT_FOUND', '当前设备凭据不可用。');
        }
        if ($credential['revoke_source_command_key'] === $input->commandKey) {
            return $this->original($credential, $hash);
        }
        DeviceCredentialEvidence::original($credential);
        if ($credential['status'] === 'revoked') {
            if ($credential['revoke_source_command_key'] === null && $credential['revoke_input_hash'] === null
                && $credential['revoke_source_result_json'] === null && $credential['revoke_basis_json'] === null) {
                return self::pending($actor);
            }
            DeviceCredentialRevocationEvidence::original($credential);
            throw BusinessException::conflict('IDENTITY_DEVICE_ALREADY_REVOKED', '设备凭据已有撤销事实，请核对原撤销记录。');
        }
        if (!in_array($credential['status'], ['enrolled', 'bound'], true)
            || (string) $credential['revocation_epoch'] !== '0'
            || $credential['revoked_at'] !== null || $credential['revoked_by_member_id'] !== null || $credential['revoke_reason'] !== null
            || $credential['revoke_source_command_key'] !== null || $credential['revoke_input_hash'] !== null
            || $credential['revoke_source_result_json'] !== null || $credential['revoke_basis_json'] !== null) {
            throw new BusinessException('IDENTITY_DEVICE_INVARIANT', 422, '设备凭据当前撤销依据不完整。');
        }
        if ((string) $credential['revision'] !== $input->expectedRevision) {
            throw BusinessException::conflict('IDENTITY_DEVICE_REVISION_CONFLICT', '设备凭据版本已变化，请核对当前版本。');
        }
        if ($input->expectedRevision === DeviceCredentialRevocationInput::UINT64_MAX) {
            throw new BusinessException('IDENTITY_DEVICE_INVARIANT', 422, '设备凭据版本已达到可用上限。');
        }
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        try {
            $lease = $this->commands->begin(IdempotencyCommand::tenant(
                $actor,
                self::PERMISSION,
                $input->commandKey,
                $hash,
                $now->modify('+24 hours'),
            ));
        } catch (ApiException $error) {
            if ($error->errorCode !== 'IDEMPOTENCY_KEY_REUSED') {
                throw $error;
            }
            throw BusinessException::conflict('IDENTITY_DEVICE_IDEMPOTENCY_MISMATCH', '原撤销命令的内容或办理身份不一致。');
        }
        if (!$lease->isExecutionOwner()) {
            return self::pending($actor);
        }
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $stamp = $now->format('Y-m-d H:i:s.v');
        $revision = bcadd($input->expectedRevision, '1', 0);
        $body = [
            'outcome' => 'accepted', 'correlation_id' => $actor->requestId,
            'resource_key' => $credentialKey, 'resource_revision' => $revision,
            'data' => [
                'status_code' => 'revoked',
                'changed_refs' => [[
                    'module' => 'peanut.identity', 'resource_type' => 'device_credential',
                    'key' => $credentialKey, 'revision' => $revision,
                ]],
                'next_actions' => [], 'revocation_epoch' => '1', 'installation_key' => $credential['bound_installation_key'],
            ],
            'problem' => null,
        ];
        $basis = [
            'schema_version' => 1, 'credential_key' => $credentialKey, 'client_key' => $credential['client_key'],
            'key_fingerprint' => $credential['key_fingerprint'], 'bound_installation_key' => $credential['bound_installation_key'],
            'bound_at' => $credential['bound_at'] === null ? null : str_replace(' ', 'T', (string) $credential['bound_at']) . 'Z',
            'bound_by_member_id' => $credential['bound_by_member_id'] === null ? null : (string) $credential['bound_by_member_id'],
            'credential_revision_before' => $input->expectedRevision, 'credential_revision_after' => $revision,
            'credential_status_before' => $credential['status'], 'credential_status_after' => 'revoked',
            'revocation_epoch_before' => '0', 'revocation_epoch_after' => '1',
            'enrollment_input_hash' => $credential['enroll_input_hash'],
            'actor_account_id' => (string) $actor->accountId, 'actor_member_id' => (string) $actor->memberId,
            'actor_client_key' => $actor->clientKey, 'reason_note' => $input->reasonNote,
            'revoked_at' => $now->format('Y-m-d\TH:i:s.v\Z'), 'correlation_id' => $actor->requestId,
        ];
        $changes = [
            'revision' => $revision, 'status' => 'revoked', 'revocation_epoch' => '1',
            'revoked_at' => $stamp, 'revoked_by_member_id' => $actor->memberId, 'revoke_reason' => $input->reasonNote,
            'revoke_source_command_key' => $input->commandKey, 'revoke_input_hash' => $hash,
            'revoke_source_result_json' => json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'revoke_basis_json' => json_encode($basis, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'updated_at' => $stamp, 'updated_by_member_id' => $actor->memberId,
        ];
        DeviceCredentialRevocationEvidence::original([...$credential, ...$changes]);
        $updated = Db::table('pa_device_credential')->where([
            'tenant_id' => $actor->tenantId, 'credential_key' => $credentialKey,
            'revision' => $input->expectedRevision, 'status' => $credential['status'], 'revocation_epoch' => '0',
        ])->update($changes);
        if ($updated !== 1) {
            throw BusinessException::conflict('IDENTITY_DEVICE_REVISION_CONFLICT', '设备凭据撤销版本已变化。');
        }
        $this->audit->tenantMember(
            $actor,
            'identity.device-credential.revoked',
            self::PERMISSION,
            'peanut.identity.device-credential',
            $credentialKey,
            ['client_key' => $credential['client_key'], 'revision' => $revision, 'revocation_epoch' => '1'],
        );
        $this->commands->complete($lease, new IdempotencyReceipt(200, $body, 'peanut.identity.device-credential', $credentialKey));
        return $body;
    }

    private function validate(string $credentialKey, #[\SensitiveParameter] RevokeDeviceCredentialInput $input): void
    {
        DeviceCredentialRevocationInput::assertValid($input);
        if (preg_match(DeviceCredentialInput::UUID_PATTERN, $credentialKey) !== 1) {
            throw BusinessException::invalid('IDENTITY_DEVICE_INPUT_INVALID', '设备凭据标识无效。');
        }
    }

    private function source(TenantContext $actor, string $commandKey): ?array
    {
        return Db::table('pa_device_credential')->where([
            'tenant_id' => $actor->tenantId, 'revoke_source_command_key' => $commandKey,
        ])->find();
    }

    private function original(#[\SensitiveParameter] array $source, string $hash): array
    {
        $body = DeviceCredentialRevocationEvidence::original($source);
        if (!hash_equals((string) $source['revoke_input_hash'], $hash)) {
            throw BusinessException::conflict('IDENTITY_DEVICE_IDEMPOTENCY_MISMATCH', '原撤销命令的内容或办理身份不一致。');
        }
        return $body;
    }

    private static function pending(TenantContext $actor): array
    {
        return [
            'outcome' => 'pending', 'correlation_id' => $actor->requestId,
            'resource_key' => null, 'resource_revision' => null, 'data' => null,
            'problem' => [
                'code' => 'IDENTITY_DEVICE_RESULT_UNKNOWN', 'message_zh' => '原设备凭据撤销结果待核实，请保留原命令。',
                'retryable' => false, 'recovery_action' => 'query_original',
            ],
        ];
    }

    private static function sourceRace(PDOException $error): bool
    {
        $info = $error->getData()['PDO Error Info'] ?? [];
        $driver = (int) ($info['Driver Error Code'] ?? 0);
        if (in_array($driver, [1205, 1213], true) || ($info['SQLSTATE'] ?? null) === '40001') {
            return true;
        }
        if (!in_array($driver, [19, 1062], true) && ($info['SQLSTATE'] ?? null) !== '23000') {
            return false;
        }
        return str_contains($error->getMessage(), 'uq_device_credential_revoke_command')
            || str_contains($error->getMessage(), 'pa_device_credential.tenant_id, pa_device_credential.revoke_source_command_key');
    }
}
