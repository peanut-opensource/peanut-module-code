<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Identity\Device;

use app\common\exception\BusinessException;
use DateTimeImmutable;
use DateTimeZone;
use PeanutAdmin\Kernel\Audit\AuditWriter;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Modules\Identity\Contract\DeviceCredentialBindings;
use PeanutAdmin\Modules\Identity\Contract\DeviceCredentialBindingQueries;
use PeanutAdmin\Modules\Identity\Contract\Dto\DeviceCredentialBinding;
use think\facade\Db;

final readonly class BindDeviceCredential implements DeviceCredentialBindings, DeviceCredentialBindingQueries
{
    private const PERMISSION = 'peanut.identity.device-credential.bind-installation';

    public function __construct(private DeviceCredentialAccess $access, private AuditWriter $audit) {}

    public function assertRegistrationAccess(TenantContext $actor, string $credentialKey): void
    {
        $this->access->assert($actor, self::PERMISSION);
        $this->credential($actor, $credentialKey);
    }

    public function bindInstallation(
        TenantContext $actor,
        string $credentialKey,
        string $installationKey,
        string $clientKey,
        #[\SensitiveParameter]
        string $deviceFingerprintHash,
    ): DeviceCredentialBinding {
        $pdo = Db::connect()->getPdo();
        if ($pdo === false || !$pdo->inTransaction()) {
            throw new BusinessException('IDENTITY_DEVICE_INVARIANT', 422, '设备凭据绑定需要调用方业务事务。');
        }
        $this->access->assert($actor, self::PERMISSION, true);
        if (preg_match(DeviceCredentialInput::UUID_PATTERN, $installationKey) !== 1
            || preg_match('/^[a-z][a-z0-9-]{0,63}$/D', $clientKey) !== 1
            || preg_match('/^[0-9a-f]{64}$/D', $deviceFingerprintHash) !== 1) {
            throw BusinessException::invalid('IDENTITY_DEVICE_INPUT_INVALID', '设备凭据安装绑定参数无效。');
        }
        $source = $this->credential($actor, $credentialKey, true);
        DeviceCredentialEvidence::original($source);
        if ($source['client_key'] !== $clientKey || !hash_equals((string) $source['device_fingerprint_hash'], $deviceFingerprintHash)) {
            throw BusinessException::conflict('IDENTITY_DEVICE_BINDING_MISMATCH', '设备凭据与安装客户端或设备依据不一致。');
        }
        if ($source['status'] === 'bound' && $source['bound_installation_key'] === $installationKey
            && $source['bound_at'] !== null && $source['bound_by_member_id'] !== null && (string) $source['revocation_epoch'] === '0') {
            return $this->reference($source);
        }
        if ($source['status'] !== 'enrolled') {
            throw BusinessException::conflict('IDENTITY_DEVICE_UNAVAILABLE', '设备凭据当前状态无法绑定此安装。');
        }
        if ((string) $source['revision'] !== '1' || (string) $source['revocation_epoch'] !== '0'
            || $source['bound_installation_key'] !== null || $source['bound_at'] !== null || $source['bound_by_member_id'] !== null) {
            throw new BusinessException('IDENTITY_DEVICE_INVARIANT', 422, '设备凭据登记状态依据不完整。');
        }
        $stamp = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.v');
        $changes = [
            'status' => 'bound', 'revision' => '2',
            'bound_installation_key' => $installationKey, 'bound_at' => $stamp, 'bound_by_member_id' => $actor->memberId,
            'updated_at' => $stamp, 'updated_by_member_id' => $actor->memberId,
        ];
        $updated = Db::table('pa_device_credential')->where([
            'tenant_id' => $actor->tenantId, 'credential_key' => $credentialKey, 'revision' => '1', 'status' => 'enrolled',
        ])->update($changes);
        if ($updated !== 1) {
            throw BusinessException::conflict('IDENTITY_DEVICE_UNAVAILABLE', '设备凭据绑定状态已变化。');
        }
        $this->audit->tenantMember(
            $actor,
            'identity.device-credential.bound',
            self::PERMISSION,
            'peanut.identity.device-credential',
            $credentialKey,
            ['installation_key' => $installationKey, 'client_key' => $clientKey, 'revision' => '2'],
        );
        return $this->reference([...$source, ...$changes]);
    }

    public function lockActiveInstallationBinding(
        TenantContext $actor,
        string $credentialKey,
        string $installationKey,
        string $clientKey,
        #[\SensitiveParameter]
        string $deviceFingerprintHash,
    ): DeviceCredentialBinding {
        $pdo = Db::connect()->getPdo();
        if ($pdo === false || !$pdo->inTransaction()) {
            throw new BusinessException('IDENTITY_DEVICE_INVARIANT', 422, '设备凭据当前绑定查询需要调用方业务事务。');
        }
        $this->access->assert($actor, self::PERMISSION, true);
        if (preg_match(DeviceCredentialInput::UUID_PATTERN, $credentialKey) !== 1
            || preg_match(DeviceCredentialInput::UUID_PATTERN, $installationKey) !== 1
            || preg_match('/^[a-z][a-z0-9-]{0,63}$/D', $clientKey) !== 1
            || preg_match('/^[0-9a-f]{64}$/D', $deviceFingerprintHash) !== 1) {
            throw BusinessException::invalid('IDENTITY_DEVICE_INPUT_INVALID', '设备凭据当前绑定查询参数无效。');
        }
        $source = $this->credential($actor, $credentialKey, true);
        DeviceCredentialEvidence::original($source);
        if ($source['status'] !== 'bound' || (string) $source['revocation_epoch'] !== '0') {
            throw BusinessException::conflict('IDENTITY_DEVICE_UNAVAILABLE', '设备凭据当前绑定不可用。');
        }
        if (!DeviceCredentialRevocationInput::isRevision((string) $source['revision'])
            || bccomp((string) $source['revision'], '2', 0) < 0
            || !DeviceCredentialRevocationInput::isRevision((string) $source['bound_by_member_id'])
            || $source['bound_installation_key'] === null
            || $source['revoked_at'] !== null || $source['revoked_by_member_id'] !== null || $source['revoke_reason'] !== null) {
            throw new BusinessException('IDENTITY_DEVICE_INVARIANT', 422, '设备凭据当前绑定依据不完整。');
        }
        foreach (['created_at', 'bound_at'] as $field) {
            $value = $source[$field];
            if (!is_string($value)
                || preg_match('/^[1-9][0-9]{3}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}\\.[0-9]{3}$/D', $value) !== 1) {
                throw new BusinessException('IDENTITY_DEVICE_INVARIANT', 422, '设备凭据绑定时刻无法核实。');
            }
            $instant = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.v', $value, new DateTimeZone('UTC'));
            if ($instant === false || $instant->format('Y-m-d H:i:s.v') !== $value) {
                throw new BusinessException('IDENTITY_DEVICE_INVARIANT', 422, '设备凭据绑定时刻无法核实。');
            }
        }
        if (strcmp($source['bound_at'], $source['created_at']) < 0) {
            throw new BusinessException('IDENTITY_DEVICE_INVARIANT', 422, '设备凭据绑定时刻早于登记时刻。');
        }
        if ($source['bound_installation_key'] !== $installationKey || $source['client_key'] !== $clientKey
            || !hash_equals((string) $source['device_fingerprint_hash'], $deviceFingerprintHash)) {
            throw BusinessException::conflict('IDENTITY_DEVICE_BINDING_MISMATCH', '设备凭据当前绑定与请求关联不一致。');
        }
        return $this->reference($source);
    }

    private function credential(TenantContext $actor, string $credentialKey, bool $lock = false): array
    {
        if (preg_match(DeviceCredentialInput::UUID_PATTERN, $credentialKey) !== 1) {
            throw BusinessException::notFound('IDENTITY_DEVICE_NOT_FOUND', '当前设备凭据不可用。');
        }
        $source = Db::table('pa_device_credential')->where([
            'tenant_id' => $actor->tenantId, 'credential_key' => $credentialKey,
        ])->lock($lock)->find();
        if ($source === null) {
            throw BusinessException::notFound('IDENTITY_DEVICE_NOT_FOUND', '当前设备凭据不可用。');
        }
        return $source;
    }

    private function reference(#[\SensitiveParameter] array $source): DeviceCredentialBinding
    {
        return new DeviceCredentialBinding(
            (string) $source['credential_key'],
            (string) $source['revision'],
            (string) $source['client_key'],
            (string) $source['device_fingerprint_hash'],
            (string) $source['key_fingerprint'],
            (string) $source['bound_installation_key'],
            (string) $source['status'],
            (string) $source['revocation_epoch'],
            str_replace(' ', 'T', (string) $source['bound_at']) . 'Z',
        );
    }
}
