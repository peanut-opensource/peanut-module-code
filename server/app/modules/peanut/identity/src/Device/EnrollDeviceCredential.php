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
use PeanutAdmin\Modules\Identity\Contract\DeviceCredentialEnrollment;
use PeanutAdmin\Modules\Identity\Contract\Dto\EnrollDeviceCredentialInput;
use PeanutAdmin\Modules\Identity\Persistence\Model\DeviceCredential;
use think\db\exception\PDOException;
use think\facade\Db;

final readonly class EnrollDeviceCredential implements DeviceCredentialEnrollment
{
    private const PERMISSION = 'peanut.identity.device-credential.enroll';

    public function __construct(
        private DeviceCredentialAccess $access,
        private IdempotentCommandExecutor $commands,
        private AuditWriter $audit,
    ) {}

    public function enroll(TenantContext $actor, #[\SensitiveParameter] EnrollDeviceCredentialInput $input): array
    {
        DeviceCredentialInput::assertValid($input);
        $pdo = Db::connect()->getPdo();
        if ($pdo !== false && $pdo->inTransaction()) {
            throw new BusinessException('IDENTITY_DEVICE_INVARIANT', 422, '设备凭据登记需要独立业务事务。');
        }
        $this->access->assert($actor, self::PERMISSION);
        $message = DeviceCredentialProof::message(
            (string) $actor->tenantId,
            (string) $actor->accountId,
            (string) $actor->memberId,
            $actor->clientKey,
            $input,
        );
        $hash = hash('sha256', $message . $input->credentialSignature);
        $publicKey = hex2bin($input->credentialPublicKey);
        $signature = hex2bin($input->credentialSignature);
        $fingerprint = hash('sha256', $publicKey);
        try {
            return Db::transaction(function () use ($actor, $input, $message, $hash, $publicKey, $signature, $fingerprint): array {
                $source = $this->source($actor, $input->commandKey);
                if ($source !== null) {
                    return $this->original($source, $hash);
                }
                $this->access->assert($actor, self::PERMISSION, true);
                if (!sodium_crypto_sign_verify_detached($signature, $message, $publicKey)) {
                    throw BusinessException::invalid('IDENTITY_DEVICE_PROOF_INVALID', '设备凭据持有证明无效。');
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
                    throw BusinessException::conflict('IDENTITY_DEVICE_IDEMPOTENCY_MISMATCH', '原登记命令的内容或办理身份不一致。');
                }
                if ($lease->isReplayable()) {
                    $source = $this->source($actor, $input->commandKey, true);
                    return $source === null ? self::pending($actor) : $this->original($source, $hash);
                }
                if (!$lease->isExecutionOwner()) {
                    return self::pending($actor);
                }
                if ($this->keyExists($actor, $fingerprint)) {
                    throw BusinessException::conflict('IDENTITY_DEVICE_KEY_REGISTERED', '该设备公钥已经登记。');
                }
                $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
                $stamp = $now->format('Y-m-d H:i:s.v');
                $key = (string) Db::query('SELECT UUID() AS credential_key')[0]['credential_key'];
                $body = [
                    'outcome' => 'accepted', 'correlation_id' => $actor->requestId,
                    'resource_key' => $key, 'resource_revision' => '1',
                    'data' => [
                        'status_code' => 'enrolled',
                        'changed_refs' => [['module' => 'peanut.identity', 'resource_type' => 'device_credential', 'key' => $key, 'revision' => '1']],
                        'next_actions' => [],
                    ],
                    'problem' => null,
                ];
                $basis = [
                    'schema_version' => 1, 'credential_key' => $key, 'client_key' => $input->clientKey,
                    'device_fingerprint_hash' => $input->deviceFingerprintHash, 'key_fingerprint' => $fingerprint,
                    'algorithm' => 'ed25519', 'credential_revision' => '1', 'credential_status' => 'enrolled',
                    'proof_hash' => hash('sha256', $signature), 'actor_account_id' => (string) $actor->accountId,
                    'actor_member_id' => (string) $actor->memberId, 'actor_client_key' => $actor->clientKey,
                    'enrolled_at' => $now->format('Y-m-d\TH:i:s.v\Z'),
                ];
                if (!(new DeviceCredential())->save([
                    'tenant_id' => $actor->tenantId, 'credential_key' => $key, 'revision' => '1',
                    'client_key' => $input->clientKey, 'device_fingerprint_hash' => $input->deviceFingerprintHash,
                    'credential_public_key' => $input->credentialPublicKey, 'key_fingerprint' => $fingerprint,
                    'credential_signature' => $input->credentialSignature, 'status' => 'enrolled',
                    'bound_installation_key' => null, 'bound_at' => null, 'bound_by_member_id' => null,
                    'revocation_epoch' => '0', 'revoked_at' => null, 'revoked_by_member_id' => null, 'revoke_reason' => null,
                    'enroll_source_command_key' => $input->commandKey, 'enroll_input_hash' => $hash,
                    'enroll_source_result_json' => $body, 'enroll_basis_json' => $basis,
                    'created_at' => $stamp, 'created_by_member_id' => $actor->memberId,
                    'updated_at' => $stamp, 'updated_by_member_id' => $actor->memberId,
                ])) {
                    throw new \RuntimeException('The device credential could not be recorded.');
                }
                $this->audit->tenantMember(
                    $actor,
                    'identity.device-credential.enrolled',
                    self::PERMISSION,
                    'peanut.identity.device-credential',
                    $key,
                    ['client_key' => $input->clientKey, 'algorithm' => 'ed25519', 'revision' => '1'],
                );
                $this->commands->complete($lease, new IdempotencyReceipt(201, $body, 'peanut.identity.device-credential', $key));
                return $body;
            });
        } catch (BusinessException | PDOException $error) {
            if ($error instanceof PDOException && !self::sourceRace($error)) {
                throw $error;
            }
            return Db::transaction(function () use ($actor, $input, $hash, $fingerprint, $error): array {
                $this->access->assert($actor, self::PERMISSION);
                $source = $this->source($actor, $input->commandKey);
                if ($source !== null) {
                    return $this->original($source, $hash);
                }
                if ($error instanceof BusinessException) {
                    throw $error;
                }
                if ($this->keyExists($actor, $fingerprint)) {
                    throw BusinessException::conflict('IDENTITY_DEVICE_KEY_REGISTERED', '该设备公钥已经登记。');
                }
                return self::pending($actor);
            });
        }
    }

    private function source(TenantContext $actor, string $commandKey, bool $lock = false): ?array
    {
        return Db::table('pa_device_credential')->where([
            'tenant_id' => $actor->tenantId, 'enroll_source_command_key' => $commandKey,
        ])->lock($lock)->find();
    }

    private function keyExists(TenantContext $actor, string $fingerprint): bool
    {
        return Db::table('pa_device_credential')->where([
            'tenant_id' => $actor->tenantId, 'key_fingerprint' => $fingerprint,
        ])->find() !== null;
    }

    private function original(#[\SensitiveParameter] array $source, string $hash): array
    {
        if (!hash_equals((string) $source['enroll_input_hash'], $hash)) {
            throw BusinessException::conflict('IDENTITY_DEVICE_IDEMPOTENCY_MISMATCH', '原登记命令的内容或办理身份不一致。');
        }
        return DeviceCredentialEvidence::original($source);
    }

    private static function pending(TenantContext $actor): array
    {
        return [
            'outcome' => 'pending', 'correlation_id' => $actor->requestId,
            'resource_key' => null, 'resource_revision' => null, 'data' => null,
            'problem' => [
                'code' => 'IDENTITY_DEVICE_RESULT_UNKNOWN', 'message_zh' => '原登记结果待核实，请保留原命令查询。',
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
        return str_contains($error->getMessage(), 'uq_device_credential_enroll_command')
            || str_contains($error->getMessage(), 'uq_device_credential_public_key')
            || str_contains($error->getMessage(), 'pa_device_credential.tenant_id, pa_device_credential.enroll_source_command_key')
            || str_contains($error->getMessage(), 'pa_device_credential.tenant_id, pa_device_credential.key_fingerprint');
    }
}
