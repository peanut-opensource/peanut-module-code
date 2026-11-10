<?php

declare(strict_types=1);

namespace app\command;

use app\common\services\upgrade\SourceSharedCredentialRecovery;
use PeanutAdmin\Kernel\Context\SystemActorDefinition;
use PeanutAdmin\Kernel\Context\SystemActorRegistry;
use PeanutAdmin\Kernel\Context\SystemContextFactory;
use PeanutAdmin\Modules\Identity\Context\Persistence\ThinkPhpSystemTenantResolver;
use PeanutAdmin\Modules\Identity\Identity\Maintenance\IdentityCredentialRecovery as Recovery;
use RuntimeException;
use think\console\Command;
use think\console\Input;
use think\console\Output;
use think\console\input\Option;

/** Local credential recovery with separate initial-grant and current shared-account admission. */
final class IdentityCredentialRecovery extends Command
{
    protected function configure(): void
    {
        $this->setName('identity:credential-recover')
            ->setDescription('Preflight or recover local Identity credentials with explicit initial or current shared admission')
            ->addOption('target', null, Option::VALUE_REQUIRED, 'tenant-owner or platform-operator')
            ->addOption('run-id', null, Option::VALUE_REQUIRED, 'The initial identity grant run id')
            ->addOption('execute', null, Option::VALUE_NONE, 'Perform the approved credential recovery; default is read-only preflight')
            ->addOption('approval-ref', null, Option::VALUE_REQUIRED, 'Root approval reference recorded in audit')
            ->addOption('password-file', null, Option::VALUE_REQUIRED, 'APP-owned 0600 one-line password file');
        $this->addOption('mode', null, Option::VALUE_REQUIRED, 'initial or current-existing-shared', 'initial');
        foreach (['owner', 'thread', 'session', 'operation-id', 'tenant-code', 'account-id', 'credential-id', 'plan-sha256'] as $name) {
            $this->addOption($name, null, Option::VALUE_REQUIRED, 'Current shared source-maintenance ' . $name);
        }
        $this->addOption('gate', null, Option::VALUE_REQUIRED, 'Registered source-maintenance gate', 'shared-development');
    }

    protected function execute(Input $input, Output $output): int
    {
        if (PHP_SAPI !== 'cli') {
            $output->writeln('{"error":"IDENTITY_RECOVERY_CLI_ONLY"}');
            return 1;
        }

        $password = null;
        try {
            $mode = (string) $input->getOption('mode');
            if ($mode === 'current-existing-shared') {
                if ((string) $input->getOption('target') !== '' || (string) $input->getOption('run-id') !== '') {
                    throw new RuntimeException('IDENTITY_RECOVERY_CURRENT_INPUT_INVALID');
                }
                $options = [];
                foreach (['owner', 'thread', 'session', 'gate', 'operation-id', 'tenant-code', 'account-id', 'credential-id', 'plan-sha256', 'approval-ref', 'password-file'] as $name) {
                    $options[str_replace('-', '_', $name)] = (string) $input->getOption($name);
                }
                foreach (['account_id', 'credential_id'] as $name) {
                    if (preg_match('/\A[1-9][0-9]{0,17}\z/D', $options[$name]) !== 1) { throw new RuntimeException('IDENTITY_RECOVERY_CURRENT_INPUT_INVALID'); }
                    $options[$name] = (int) $options[$name];
                }
                $options['execute'] = ($input->getOption('execute') ?? false) === true;
                $result = $this->getApp()->make(SourceSharedCredentialRecovery::class)->run($options);
                $output->writeln(json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
                return 0;
            }
            if ($mode !== 'initial') { throw new RuntimeException('IDENTITY_RECOVERY_MODE_INVALID'); }
            $target = (string) $input->getOption('target');
            $runId = (string) $input->getOption('run-id');
            if (!in_array($target, ['tenant-owner', 'platform-operator'], true)) {
                throw new RuntimeException('IDENTITY_RECOVERY_TARGET_INVALID');
            }

            $serverRoot = dirname(__DIR__, 2);
            $appRoot = realpath(dirname($serverRoot));
            if (!is_string($appRoot) || !is_dir($appRoot)) {
                throw new RuntimeException('IDENTITY_RECOVERY_APP_ROOT_INVALID');
            }
            $receipt = $this->bindingReceipt($serverRoot);
            $plan = $this->approvedPlan($serverRoot, $receipt, $target, $runId, (string) $input->getOption('approval-ref'));
            $this->assertResourceBinding($serverRoot, $receipt, $plan);

            $factory = new SystemContextFactory(
                new SystemActorRegistry([
                    new SystemActorDefinition(
                        Recovery::TENANT_OWNER_ACTOR,
                        'tenant',
                        [Recovery::TENANT_OWNER_OPERATION],
                    ),
                    new SystemActorDefinition(
                        Recovery::PLATFORM_OPERATOR_ACTOR,
                        'platform',
                        [Recovery::PLATFORM_OPERATOR_OPERATION],
                    ),
                ]),
                new ThinkPhpSystemTenantResolver(),
            );
            $recovery = $this->getApp()->make(Recovery::class);
            $requestId = 'identity-recovery-' . bin2hex(random_bytes(12));
            $privateBinding = [
                'app_slug' => $receipt['app_slug'],
                'resource' => $receipt['resource'],
                'identities' => $receipt['identities'],
                'password_refs' => $receipt['password_refs'],
                'run_id' => $receipt['run_id'],
                'source_ref' => $receipt['source_ref'],
                'target' => $target,
                'approved_plan' => $plan,
                'caller_origin' => $plan['caller_origin'],
            ];

            if ($target === 'tenant-owner') {
                $context = $factory->tenant(
                    Recovery::TENANT_OWNER_ACTOR,
                    Recovery::TENANT_OWNER_OPERATION,
                    (string) $receipt['tenant_code'],
                    $runId,
                );
                $status = $recovery->inspectTenantOwner($context, $privateBinding);
            } else {
                $context = $factory->platform(
                    Recovery::PLATFORM_OPERATOR_ACTOR,
                    Recovery::PLATFORM_OPERATOR_OPERATION,
                    $runId,
                );
                $status = $recovery->inspectPlatformOperator($context, $privateBinding);
            }

            if (($input->getOption('execute') ?? false) !== true) {
                $output->writeln(json_encode([
                    'mode' => 'preflight_only',
                    'target' => $target,
                    'run_id' => $runId,
                    'status' => $status,
                ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
                return ($status['eligible'] ?? false) === true ? 0 : 2;
            }
            if (($status['eligible'] ?? false) !== true) {
                throw new RuntimeException('IDENTITY_RECOVERY_PRECONDITION_FAILED');
            }

            $approvalRef = (string) $plan['approval_ref'];
            $passwordRef = (string) ($receipt['password_refs'][$target] ?? '');
            if ($passwordRef === '') {
                throw new RuntimeException('IDENTITY_RECOVERY_PASSWORD_REF_INVALID');
            }
            if ((string) $input->getOption('password-file') !== $passwordRef) {
                throw new RuntimeException('IDENTITY_RECOVERY_PASSWORD_REF_INVALID');
            }
            $password = $this->readPassword($this->resolvePasswordPath($appRoot, $passwordRef));
            $principal = $this->maintenancePrincipal();

            if ($target === 'tenant-owner') {
                $result = $recovery->recoverTenantOwner(
                    $context,
                    $password,
                    $requestId,
                    $runId,
                    $approvalRef,
                    $principal,
                    $passwordRef,
                    $privateBinding,
                );
            } else {
                $result = $recovery->recoverPlatformOperator(
                    $context,
                    $password,
                    $requestId,
                    $runId,
                    $approvalRef,
                    $principal,
                    $passwordRef,
                    $privateBinding,
                );
            }
            $output->writeln(json_encode(['recovered' => true, 'target' => $target, 'result' => $result], JSON_THROW_ON_ERROR));
            return 0;
        } catch (\Throwable $exception) {
            $code = $exception instanceof RuntimeException ? $exception->getMessage() : '';
            if (preg_match('/^IDENTITY_(?:RECOVERY|CURRENT_RECOVERY)_[A-Z0-9_]+$/D', $code) !== 1) {
                $code = 'IDENTITY_RECOVERY_FAILED';
            }
            $failure = ['error' => $code];
            if (($mode ?? null) === 'current-existing-shared') {
                $failure['diagnostic'] = ['class' => get_class($exception), 'file' => basename($exception->getFile()),
                    'line' => $exception->getLine(), 'reason_sha256' => hash('sha256', $exception->getMessage())];
                $failure['recovery'] = 'If an attempt exists, reconcile its operation ID and both audit streams; never replay an unknown outcome.';
            }
            $output->writeln(json_encode($failure, JSON_THROW_ON_ERROR));
            return 1;
        } finally {
            if (is_string($password) && function_exists('sodium_memzero')) {
                sodium_memzero($password);
            }
        }
    }

    /** @return array<string, mixed> */
    private function bindingReceipt(string $serverRoot): array
    {
        $path = $serverRoot . '/private/initial-identity-grant-binding.json';
        $this->assertPrivateRegularFile($path, 0600);
        $value = json_decode((string) file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($value) || !is_string($value['run_id'] ?? null)
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/D', $value['run_id']) !== 1
            || !is_string($value['app_slug'] ?? null) || $value['app_slug'] === ''
            || !is_string($value['source_ref'] ?? null) || trim($value['source_ref']) === ''
            || !is_string($value['tenant_code'] ?? null) || $value['tenant_code'] === ''
            || !is_array($value['identities'] ?? null)
            || !is_array($value['resource'] ?? null)
            || !is_array($value['password_refs'] ?? null)
            || !is_string($value['resource']['deployment_target'] ?? null)
            || !is_string($value['resource']['database_resource_id'] ?? null)
            || !is_string($value['resource']['database_endpoint_id'] ?? null)
            || !is_string($value['resource']['schema'] ?? null)
            || !is_int($value['identities']['tenant_id'] ?? null)
            || !is_int($value['identities']['owner_account_id'] ?? null)
            || !is_int($value['identities']['owner_member_id'] ?? null)
            || !is_int($value['identities']['platform_operator_id'] ?? null)
            || !is_int($value['identities']['platform_account_id'] ?? null)
            || !is_int($value['identities']['owner_credential_id'] ?? null)
            || !is_int($value['identities']['platform_credential_id'] ?? null)
            || !is_string($value['password_refs']['tenant-owner'] ?? null)
            || !is_string($value['password_refs']['platform-operator'] ?? null)
            || array_diff(['tenant_id', 'owner_account_id', 'owner_member_id', 'owner_credential_id', 'platform_operator_id', 'platform_account_id', 'platform_credential_id'], array_keys($value['identities'])) !== []
            || array_diff(array_keys($value['identities']), ['tenant_id', 'owner_account_id', 'owner_member_id', 'owner_credential_id', 'platform_operator_id', 'platform_account_id', 'platform_credential_id']) !== []
            || $value['identities']['owner_account_id'] === $value['identities']['platform_account_id']) {
            throw new RuntimeException('IDENTITY_RECOVERY_BINDING_RECEIPT_INVALID');
        }
        return $value;
    }

    /** @param array<string,mixed> $receipt @return array<string,mixed> */
    private function approvedPlan(string $serverRoot, array $receipt, string $target, string $runId, string $approvalRef): array
    {
        $path = $serverRoot . '/private/credential-recovery-approved-plan.json';
        $this->assertPrivateRegularFile($path, 0600);
        $plan = json_decode((string) file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
        $operations = ['tenant-owner', 'platform-operator'];
        $selectedOperation = $target;
        $principal = $this->maintenancePrincipal();
        $currentOrigin = ['kind' => 'local-cli', 'uid' => function_exists('posix_geteuid') ? posix_geteuid() : null, 'username' => function_exists('posix_getpwuid') && function_exists('posix_geteuid') ? (posix_getpwuid(posix_geteuid())['name'] ?? null) : null];
        if (!is_array($plan)
            || !is_string($plan['approval_ref'] ?? null) || $approvalRef === '' || !hash_equals($plan['approval_ref'], $approvalRef)
            || ($plan['expected_app_slug'] ?? null) !== $receipt['app_slug']
            || ($plan['resource'] ?? null) !== $receipt['resource']
            || ($plan['target_ids'] ?? null) !== $receipt['identities']
            || ($plan['password_refs'] ?? null) !== $receipt['password_refs']
            || ($plan['run_id'] ?? null) !== $receipt['run_id'] || $runId !== $receipt['run_id']
            || ($plan['source_ref'] ?? null) !== $receipt['source_ref']
            || ($plan['status'] ?? null) !== 'approved'
            || !is_string($plan['source_ref'] ?? null) || trim($plan['source_ref']) === ''
            || !is_array($plan['allowed_operations'] ?? null)
            || array_values($plan['allowed_operations']) !== $operations
            || !in_array($selectedOperation, $plan['allowed_operations'], true)
            || ($plan['caller_origin'] ?? null) !== $currentOrigin
            || $principal === '') {
            throw new RuntimeException('IDENTITY_RECOVERY_APPROVED_PLAN_MISMATCH');
        }
        return $plan;
    }

    /** @param array<string, mixed> $receipt */
    private function assertResourceBinding(string $serverRoot, array $receipt, array $plan): void
    {
        $externalRegistry = getenv('PEANUT_RESOURCE_REGISTRY');
        if ($externalRegistry !== false && trim($externalRegistry) !== '') {
            throw new RuntimeException('IDENTITY_RECOVERY_EXTERNAL_REGISTRY_FORBIDDEN');
        }
        require_once $serverRoot . '/database/environment-guard.php';
        $guarded = guardedDatabaseConfig();
        if ($receipt['app_slug'] !== $this->applicationSlug($serverRoot)
            || $receipt['resource']['deployment_target'] !== $guarded['deployment_target']
            || !hash_equals((string) $receipt['resource']['database_resource_id'], $guarded['resource_id'])
            || !hash_equals((string) $receipt['resource']['database_endpoint_id'], $guarded['endpoint_id'])
            || !hash_equals((string) $receipt['resource']['schema'], $guarded['database'])
            || ($plan['expected_app_slug'] ?? null) !== $receipt['app_slug']) {
            throw new RuntimeException('IDENTITY_RECOVERY_RESOURCE_BINDING_INVALID');
        }

        $connection = $this->getApp()->config->get('database.connections.mysql', []);
        if (!is_array($connection)
            || ($this->getApp()->config->get('database.default') !== 'mysql')
            || ($connection['type'] ?? null) !== 'mysql'
            || (string) ($connection['hostname'] ?? '') !== (string) $guarded['host']
            || (string) ($connection['hostport'] ?? '') !== (string) $guarded['port']
            || (string) ($connection['database'] ?? '') !== (string) $guarded['database']
            || !hash_equals((string) ($connection['username'] ?? ''), (string) $guarded['user'])
            || !hash_equals((string) ($connection['password'] ?? ''), (string) $guarded['password'])) {
            throw new RuntimeException('IDENTITY_RECOVERY_FRAMEWORK_CONNECTION_MISMATCH');
        }
    }

    private function applicationSlug(string $serverRoot): string
    {
        if (is_file($serverRoot . '/.peanut/release-identity.json')) {
            return \app\common\value\installation\ServerReleaseIdentity::resolve($serverRoot)->applicationSlug();
        }
        return \app\common\value\installation\ApplicationSourceIdentity::load($serverRoot)->applicationSlug();
    }

    private function resolvePasswordPath(string $appRoot, string $reference): string
    {
        $path = str_starts_with($reference, '/') ? $reference : $appRoot . '/' . $reference;
        if (is_link($path)) {
            throw new RuntimeException('IDENTITY_RECOVERY_PASSWORD_REF_INVALID');
        }
        $privateRoot = realpath($appRoot . '/server/private/credentials');
        $resolved = realpath($path);
        if (!is_string($privateRoot) || !is_string($resolved)
            || !str_starts_with($resolved, $privateRoot . DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('IDENTITY_RECOVERY_PASSWORD_REF_INVALID');
        }
        return $resolved;
    }

    private function readPassword(string $path): string
    {
        $this->assertPrivateRegularFile($path, 0600);
        $contents = file_get_contents($path);
        if (!is_string($contents) || $contents === '' || strpbrk($contents, "\0\r\n") !== false) {
            throw new RuntimeException('IDENTITY_RECOVERY_PASSWORD_FILE_INVALID');
        }
        return $contents;
    }

    private function assertPrivateRegularFile(string $path, int $mode): void
    {
        if (!str_starts_with($path, '/') || is_link($path) || !is_file($path)) {
            throw new RuntimeException('IDENTITY_RECOVERY_PRIVATE_FILE_INVALID');
        }
        $stat = lstat($path);
        $currentUid = function_exists('posix_geteuid') ? posix_geteuid() : null;
        if (!is_array($stat)
            || ($stat['nlink'] ?? 0) !== 1
            || (($stat['mode'] ?? 0) & 0170000) !== 0100000
            || (($stat['mode'] ?? 0) & 0777) !== $mode
            || $currentUid === null
            || ($stat['uid'] ?? null) !== $currentUid
            || realpath($path) !== $path) {
            throw new RuntimeException('IDENTITY_RECOVERY_PRIVATE_FILE_PERMISSIONS_INVALID');
        }
    }

    private function maintenancePrincipal(): string
    {
        if (!function_exists('posix_geteuid') || !function_exists('posix_getpwuid')) {
            throw new RuntimeException('IDENTITY_RECOVERY_LOCAL_PRINCIPAL_UNAVAILABLE');
        }
        $uid = posix_geteuid();
        $user = posix_getpwuid($uid);
        if (!is_array($user) || !is_string($user['name'] ?? null) || $user['name'] === '') {
            throw new RuntimeException('IDENTITY_RECOVERY_LOCAL_PRINCIPAL_UNAVAILABLE');
        }
        return 'uid:' . $uid . ':' . $user['name'];
    }
}
