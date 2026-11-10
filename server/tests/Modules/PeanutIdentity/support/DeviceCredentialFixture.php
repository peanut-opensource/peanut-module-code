<?php

declare(strict_types=1);

use app\common\execution\AdminExecutionContext;
use app\common\execution\CurrentExecutionContext;
use app\common\execution\ExecutionContextStore;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Module\ModuleAvailability;
use PeanutAdmin\Kernel\Module\ModuleException;
use PeanutAdmin\Kernel\Tenancy\TenantScope;
use PeanutAdmin\Modules\Identity\Contract\Dto\EnrollDeviceCredentialInput;
use PeanutAdmin\Modules\Identity\Device\BindDeviceCredential;
use PeanutAdmin\Modules\Identity\Device\DeviceCredentialAccess;
use PeanutAdmin\Modules\Identity\Device\DeviceCredentialProof;
use PeanutAdmin\Modules\Identity\Device\EnrollDeviceCredential;
use think\facade\Db;

require __DIR__ . '/NativeRuntimeFixture.php';

$identityModule = $server . '/app/modules/peanut/identity';
$loader->setPsr4('PeanutAdmin\\Modules\\Identity\\', $identityModule . '/src');
$roles->authorizedMembers = [1, 2, 20];
$roles->grants = ['peanut.identity.device-credential.enroll', 'peanut.identity.device-credential.bind-installation'];
$contexts = new ExecutionContextStore();
$execution = new CurrentExecutionContext($contexts);
$modules = new class implements ModuleAvailability {
    public bool $available = true;
    public array $calls = [];
    public function assertDeployment(string $moduleKey, bool $lock = false): void
    {
        $this->calls[] = [$moduleKey, $lock, Db::connect()->getPdo()->inTransaction()];
        businessCheck($moduleKey === 'peanut.identity', 'Device credentials use their protected Identity owner.');
        businessCheck(!$lock || Db::connect()->getPdo()->inTransaction(), 'Deployment lock belongs to the active transaction.');
        if (!$this->available) {
            throw new ModuleException('MODULE_NOT_AVAILABLE', 'Identity unavailable.');
        }
    }
    public function assertAvailable(TenantScope $scope, string $moduleKey, DateTimeImmutable $now, bool $lock = false): void
    {
        throw new LogicException('Protected Identity uses its actual deployment gate.');
    }
    public function assertTenant(TenantScope $scope, string $moduleKey, DateTimeImmutable $now, bool $lock = false): void
    {
        throw new LogicException('Protected Identity is not a tenant-selectable business module.');
    }
};
$access = new DeviceCredentialAccess($execution, $authorization, $modules);
$service = new EnrollDeviceCredential($access, $commands, $audit);
$bindings = new BindDeviceCredential($access, $audit);
$actor = businessActor();
$asActor = static function (callable $operation, ?TenantContext $current = null) use ($actor, $contexts) {
    $current ??= $actor;
    return $contexts->run(
        new AdminExecutionContext($current, 'http.admin.device-credential', [
            'id' => $current->memberId, 'tenant_id' => $current->tenantId, 'account_id' => $current->accountId,
        ]),
        static fn() => $operation($current),
    );
};
$enroll = static fn(EnrollDeviceCredentialInput $input, ?TenantContext $current = null): array =>
    $asActor(static fn(TenantContext $trusted): array => $service->enroll($trusted, $input), $current);

function deviceSigned(TenantContext $actor, int $command, ?string $keyPair = null, string $client = 'dcs-pos'): EnrollDeviceCredentialInput
{
    $keyPair ??= sodium_crypto_sign_keypair();
    $input = new EnrollDeviceCredentialInput(
        $client,
        hash('sha256', 'synthetic-device-' . $command),
        bin2hex(sodium_crypto_sign_publickey($keyPair)),
        str_repeat('0', 128),
        businessKey($command),
    );
    $message = DeviceCredentialProof::message(
        (string) $actor->tenantId,
        (string) $actor->accountId,
        (string) $actor->memberId,
        $actor->clientKey,
        $input,
    );
    return new EnrollDeviceCredentialInput(
        $input->clientKey,
        $input->deviceFingerprintHash,
        $input->credentialPublicKey,
        bin2hex(sodium_crypto_sign_detached($message, sodium_crypto_sign_secretkey($keyPair))),
        $input->commandKey,
    );
}

Db::execute('CREATE TABLE pa_device_credential (
    id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL, credential_key TEXT NOT NULL, revision TEXT NOT NULL,
    client_key TEXT NOT NULL, device_fingerprint_hash TEXT NOT NULL, credential_public_key TEXT NOT NULL,
    key_fingerprint TEXT NOT NULL, credential_signature TEXT NOT NULL, status TEXT NOT NULL,
    bound_installation_key TEXT, bound_at TEXT, bound_by_member_id INTEGER, revocation_epoch TEXT NOT NULL,
    revoked_at TEXT, revoked_by_member_id INTEGER, revoke_reason TEXT,
    revoke_source_command_key TEXT, revoke_input_hash TEXT, revoke_source_result_json TEXT, revoke_basis_json TEXT,
    enroll_source_command_key TEXT NOT NULL, enroll_input_hash TEXT NOT NULL,
    enroll_source_result_json TEXT NOT NULL, enroll_basis_json TEXT NOT NULL,
    created_at TEXT NOT NULL, created_by_member_id INTEGER NOT NULL, updated_at TEXT NOT NULL, updated_by_member_id INTEGER NOT NULL,
    UNIQUE(tenant_id, credential_key), UNIQUE(tenant_id, id), UNIQUE(tenant_id, key_fingerprint), UNIQUE(tenant_id, enroll_source_command_key),
    UNIQUE(tenant_id, revoke_source_command_key),
    CHECK ((revoke_source_command_key IS NULL AND revoke_input_hash IS NULL AND revoke_source_result_json IS NULL AND revoke_basis_json IS NULL)
        OR (revoke_source_command_key IS NOT NULL AND revoke_input_hash IS NOT NULL AND revoke_source_result_json IS NOT NULL AND revoke_basis_json IS NOT NULL AND status = "revoked")),
    CHECK (CAST(revision AS INTEGER) >= 1), CHECK (status IN ("enrolled", "bound", "revoked")),
    CHECK ((bound_installation_key IS NULL AND bound_at IS NULL AND bound_by_member_id IS NULL AND status IN ("enrolled", "revoked"))
        OR (bound_installation_key IS NOT NULL AND bound_at IS NOT NULL AND bound_by_member_id IS NOT NULL AND status IN ("bound", "revoked"))),
    CHECK ((status IN ("enrolled", "bound") AND revocation_epoch = "0" AND revoked_at IS NULL AND revoked_by_member_id IS NULL AND revoke_reason IS NULL)
        OR (status = "revoked" AND CAST(revocation_epoch AS INTEGER) >= 1 AND revoked_at IS NOT NULL AND revoked_by_member_id IS NOT NULL AND revoke_reason IS NOT NULL)))');
$state = static function (): array {
    $snapshot = [];
    foreach (['pa_device_credential', 'business_test_audit', 'pa_tenant_idempotency_record'] as $table) {
        $snapshot[$table] = Db::table($table)->order('id')->select()->toArray();
    }
    return $snapshot;
};
