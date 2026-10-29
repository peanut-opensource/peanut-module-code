<?php

declare(strict_types=1);

namespace app\common\services\upgrade;

use PeanutAdmin\Kernel\Context\SystemActorDefinition;
use PeanutAdmin\Kernel\Context\SystemActorRegistry;
use PeanutAdmin\Kernel\Context\SystemContextFactory;
use PeanutAdmin\Modules\Identity\Context\Persistence\ThinkPhpSystemTenantResolver;
use PeanutAdmin\Modules\Identity\Identity\Maintenance\IdentityCredentialRecovery;
use app\platform\infrastructure\module\ThinkPhpModuleGovernanceProvider;
use PDO;
use RuntimeException;
use think\App;
use think\facade\Db;

/** Native source-only maintenance of one credential shared by the two current administrator identities. */
final readonly class SourceSharedCredentialRecovery
{
    private const EFFECTS = ['one_credential_password', 'one_credential_revision', 'one_credential_failed_attempts_reset', 'one_account_security_revision',
        'all_tenant_memberships_explicitly_bound', 'both_surfaces_session_token_revocation',
        'account_challenge_revocation', 'both_security_and_business_audiences'];

    public function __construct(private App $app) {}

    public function run(array $options): array
    {
        $root = rtrim($this->app->getRootPath(), DIRECTORY_SEPARATOR);
        $operation = $options['operation_id'];
        if (preg_match('/\A[a-z0-9][a-z0-9._-]{0,63}\z/D', $operation) !== 1 || $options['approval_ref'] === ''
            || $options['tenant_code'] === '' || $options['account_id'] < 1 || $options['credential_id'] < 1) {
            self::fail('INPUT_INVALID');
        }
        $actor = array_intersect_key($options, array_flip(['owner', 'thread', 'session', 'gate']));
        $issuer = new SourceDevelopmentMaintenanceAdmission($root);
        $admission = $issuer->capture($actor, false);
        // This is a DML window, not permission inferred from a DDL plan or a read lease.
        if ($admission['authority']['operation'] !== 'exclusive') { self::fail('WRITE_WINDOW_REQUIRED'); }
        $directory = $root . '/private/credential-maintenance';
        $credentials = $root . '/private/credentials';
        $resources = \activeLeaseResources(\requiredMaintenanceEnvironment('PEANUT_RESOURCE_LEASE_PROOF'));
        foreach ([$directory, $credentials] as $path) {
            if (!in_array($path, $resources['output-dir'] ?? [], true)) { self::fail('OUTPUT_LEASE_REQUIRED'); }
            $this->privateDirectory($path, $root);
        }
        $passwordRef = $options['password_file'];
        if (preg_match('~\Aserver/private/credentials/[a-zA-Z0-9._-]+\z~D', $passwordRef) !== 1) { self::fail('PASSWORD_REF_INVALID'); }
        $passwordPath = dirname($root) . '/' . $passwordRef;
        $passwordBytes = $this->readPrivate($passwordPath, 4096);
        $password = null;
        try {
            $passwordDigest = hash('sha256', $passwordBytes);
            $this->assertFrameworkBinding();
            $connection = Db::connect();
            $connection->query('SELECT CONNECTION_ID()', [], true);
            $pdo = $connection->getPdo();
            if (!$pdo instanceof PDO || $pdo->inTransaction()) { self::fail('FRAMEWORK_CONNECTION_INVALID'); }
            $database = $this->databaseIdentity($pdo);
            $this->assertFrameworkBinding();
            $package = $this->adoptedIdentity();
            $factory = new SystemContextFactory(new SystemActorRegistry([
                new SystemActorDefinition(IdentityCredentialRecovery::TENANT_OWNER_ACTOR, 'tenant', [IdentityCredentialRecovery::TENANT_OWNER_OPERATION]),
                new SystemActorDefinition(IdentityCredentialRecovery::PLATFORM_OPERATOR_ACTOR, 'platform', [IdentityCredentialRecovery::PLATFORM_OPERATOR_OPERATION]),
            ]), new ThinkPhpSystemTenantResolver());
            $tenant = $factory->tenant(IdentityCredentialRecovery::TENANT_OWNER_ACTOR, IdentityCredentialRecovery::TENANT_OWNER_OPERATION, $options['tenant_code'], $operation);
            $platform = $factory->platform(IdentityCredentialRecovery::PLATFORM_OPERATOR_ACTOR, IdentityCredentialRecovery::PLATFORM_OPERATOR_OPERATION, $operation);
            $recovery = $this->app->make(IdentityCredentialRecovery::class);
            $snapshot = $recovery->inspectCurrentSharedAccount($tenant, $platform, $options['account_id'], $options['credential_id']);
            $planPath = $directory . '/' . $operation . '.plan.json';
            $principal = ['kind' => 'local-cli', 'uid' => posix_geteuid(), 'username' => posix_getpwuid(posix_geteuid())['name']];
            if (!$options['execute']) {
                $plan = ['protocol' => 'peanut.current-shared-credential-maintenance.v1', 'mode' => 'current-existing-shared',
                    'status' => 'approved', 'operation_id' => $operation, 'approval_ref' => $options['approval_ref'],
                    'caller_origin' => $principal, 'admission' => $admission, 'database' => $database, 'package' => $package,
                    'tenant_code' => $options['tenant_code'], 'snapshot' => $snapshot, 'password_ref' => $passwordRef,
                    'password_sha256' => $passwordDigest, 'observed_at' => time(),
                    'expires_at' => min(time() + 3600, (int) $admission['authority']['expires_at']),
                    'effects' => self::EFFECTS];
                $bytes = $this->encode($plan);
                $this->createPrivate($planPath, $bytes);
                return ['mode' => 'preflight_only', 'plan_path' => $planPath, 'plan_sha256' => hash('sha256', $bytes),
                    'operation_id' => $operation, 'affected_surfaces' => ['tenant', 'platform'],
                    'affected_tenant_ids' => $snapshot['affected_tenant_ids'], 'mutation' => 'not_executed'];
            }
            $bytes = $this->readPrivate($planPath);
            $digest = hash('sha256', $bytes);
            if (preg_match('/\A[a-f0-9]{64}\z/D', $options['plan_sha256']) !== 1 || !hash_equals($options['plan_sha256'], $digest)) { self::fail('PLAN_CHANGED'); }
            $plan = json_decode($bytes, true, 64, JSON_THROW_ON_ERROR);
            if (($plan['protocol'] ?? null) !== 'peanut.current-shared-credential-maintenance.v1'
                || ($plan['mode'] ?? null) !== 'current-existing-shared' || ($plan['status'] ?? null) !== 'approved'
                || ($plan['operation_id'] ?? null) !== $operation || ($plan['approval_ref'] ?? null) !== $options['approval_ref']
                || ($plan['caller_origin'] ?? null) !== $principal || ($plan['admission'] ?? null) !== $admission
                || ($plan['database'] ?? null) !== $database || ($plan['package'] ?? null) !== $package
                || ($plan['tenant_code'] ?? null) !== $options['tenant_code'] || ($plan['snapshot'] ?? null) !== $snapshot
                || ($plan['password_ref'] ?? null) !== $passwordRef || ($plan['password_sha256'] ?? null) !== $passwordDigest
                || !is_int($plan['observed_at'] ?? null) || $plan['observed_at'] > time()
                || $plan['observed_at'] < (int) $admission['authority']['created_at'] || ($plan['effects'] ?? null) !== self::EFFECTS
                || !is_int($plan['expires_at'] ?? null) || $plan['expires_at'] <= time()
                || $plan['expires_at'] <= $plan['observed_at'] || $plan['expires_at'] > $plan['observed_at'] + 3600
                || $plan['expires_at'] > (int) $admission['authority']['expires_at']) { self::fail('PLAN_BINDING_CHANGED'); }
            $guard = function () use ($issuer, $actor, $admission, $pdo, $database, $package, $planPath, $digest, $passwordPath, $passwordDigest, $plan): void {
                if ($issuer->capture($actor, false) !== $admission || Db::connect()->getPdo() !== $pdo
                    || $this->databaseIdentity($pdo) !== $database || $this->adoptedIdentity() !== $package
                    || hash('sha256', $this->readPrivate($planPath)) !== $digest
                    || hash('sha256', $this->readPrivate($passwordPath, 4096)) !== $passwordDigest || $plan['expires_at'] <= time()) {
                    self::fail('EXECUTION_BINDING_CHANGED');
                }
                $this->assertFrameworkBinding();
            };
            $guard();
            if (preg_match('/\A[^\r\n]+\n?\z/D', $passwordBytes) !== 1) { self::fail('PASSWORD_FILE_INVALID'); }
            $password = rtrim($passwordBytes, "\n");
            // Creation is exclusive and precedes any mutation. Its presence always prohibits blind replay,
            // including when a commit or result write has an unknown outcome.
            $attemptPath = $directory . '/' . $operation . '.attempt.json';
            $this->createPrivate($attemptPath, $this->encode(['protocol' => 'peanut.current-shared-credential-attempt.v1',
                'operation_id' => $operation, 'plan_sha256' => $digest, 'state' => 'dispatched', 'observed_at' => time()]));
            $plan['plan_sha256'] = $digest;
            $result = $recovery->recoverCurrentSharedAccount($tenant, $platform, $password, $plan, $guard);
            $receipt = ['protocol' => 'peanut.current-shared-credential-result.v1', 'operation_id' => $operation,
                'plan_sha256' => $digest, 'status' => 'completed', 'result' => $result,
                'affected_surfaces' => ['tenant', 'platform'], 'affected_tenant_ids' => $snapshot['affected_tenant_ids'],
                'failed_attempts_reset' => true,
                'observed_at' => time(), 'login' => 'not_verified'];
            $resultPath = $directory . '/' . $operation . '.result.json';
            $resultBytes = $this->encode($receipt);
            $this->createPrivate($resultPath, $resultBytes);
            return ['status' => 'completed', 'operation_id' => $operation, 'result_path' => $resultPath,
                'result_sha256' => hash('sha256', $resultBytes), 'password_ref' => $passwordRef,
                'affected_surfaces' => ['tenant', 'platform'], 'affected_tenant_ids' => $snapshot['affected_tenant_ids'],
                'failed_attempts_reset' => true,
                'login' => 'not_verified'];
        } finally {
            if (function_exists('sodium_memzero')) {
                sodium_memzero($passwordBytes);
                if (is_string($password)) { sodium_memzero($password); }
            }
        }
    }

    private function adoptedIdentity(): array
    {
        $plan = $this->app->make(ThinkPhpModuleGovernanceProvider::class)->pluginLifecycle()->reconcilePlan('peanut.identity');
        return $this->adoptedIdentityPlan($plan);
    }

    private function adoptedIdentityPlan(array $plan): array
    {
        if (($plan['action'] ?? null) !== 'planned_unchanged' || ($plan['may_apply'] ?? null) !== true
            || ($plan['planning_complete'] ?? null) !== true || ($plan['application_prerequisites'] ?? null) !== []
            || ($plan['key'] ?? null) !== 'peanut.identity' || !is_string($plan['version'] ?? null) || $plan['version'] === ''
            || ($plan['from_version'] ?? null) !== $plan['version'] || !is_string($plan['source'] ?? null) || $plan['source'] === ''
            || !is_array($plan['modules'] ?? null) || !array_is_list($plan['modules']) || count($plan['modules']) !== 1) {
            self::fail('CURRENT_IDENTITY_ADOPTION_REQUIRED');
        }
        foreach (['artifact_sha256', 'lock_digest', 'manifest_sha256'] as $field) {
            if (!is_string($plan[$field] ?? null) || preg_match('/\A[a-f0-9]{64}\z/D', $plan[$field]) !== 1) { self::fail('CURRENT_IDENTITY_ADOPTION_REQUIRED'); }
        }
        $module = $plan['modules'][0];
        if (($module['module_key'] ?? null) !== 'peanut.identity' || ($module['may_apply'] ?? null) !== true
            || ($module['planning_complete'] ?? null) !== true || ($module['application_prerequisites'] ?? []) !== []
            || !is_string($module['manifest_sha256'] ?? null) || preg_match('/\A[a-f0-9]{64}\z/D', $module['manifest_sha256']) !== 1
            || !is_array($module['migrations'] ?? null) || !array_is_list($module['migrations']) || $module['migrations'] === []) {
            self::fail('CURRENT_IDENTITY_ADOPTION_REQUIRED');
        }
        $seen = [];
        foreach ($module['migrations'] as $migration) {
            $key = $migration['migration_key'] ?? null;
            if (!is_string($key) || !str_starts_with($key, 'peanut.identity:') || isset($seen[$key])
                || ($migration['disposition'] ?? null) !== 'already_applied' || ($migration['ledger_status'] ?? null) !== 'applied'
                || !is_string($migration['sha256'] ?? null) || preg_match('/\A[a-f0-9]{64}\z/D', $migration['sha256']) !== 1) {
                self::fail('CURRENT_IDENTITY_ADOPTION_REQUIRED');
            }
            $seen[$key] = true;
        }
        return array_intersect_key($plan, array_flip(['key', 'version', 'source', 'artifact_sha256', 'lock_digest', 'manifest_sha256', 'from_version']))
            + ['modules_sha256' => hash('sha256', $this->encode($plan['modules']))];
    }

    private function assertFrameworkBinding(): void
    {
        $guarded = \guardedDatabaseConfig();
        $connection = $this->app->config->get('database.connections.mysql', []);
        if ($this->app->config->get('database.default') !== 'mysql' || !is_array($connection)) { self::fail('FRAMEWORK_CONNECTION_INVALID'); }
        foreach (['type' => 'mysql', 'hostname' => $guarded['host'], 'hostport' => $guarded['port'],
            'database' => $guarded['database'], 'username' => $guarded['user'], 'password' => $guarded['password']] as $key => $value) {
            if ((string) ($connection[$key] ?? '') !== (string) $value) { self::fail('FRAMEWORK_CONNECTION_INVALID'); }
        }
    }

    private function databaseIdentity(PDO $pdo): array
    {
        $row = $pdo->query('SELECT @@server_uuid AS server_uuid,@@hostname AS hostname,@@port AS port,DATABASE() AS database_name')->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row) || ($row['database_name'] ?? null) !== \guardedDatabaseConfig()['database']) { self::fail('DATABASE_CHANGED'); }
        return $row;
    }

    private function privateDirectory(string $path, string $serverRoot): void
    {
        $parent = dirname($path);
        if (realpath($parent) !== $parent || !str_starts_with($parent . '/', $serverRoot . '/private/')
            || is_link($path) || (!is_dir($path) && !@mkdir($path, 0700))) { self::fail('PRIVATE_DIRECTORY_INVALID'); }
        $stat = lstat($path);
        if (realpath($path) !== $path || !is_array($stat) || $stat['uid'] !== posix_geteuid() || ($stat['mode'] & 0777) !== 0700) { self::fail('PRIVATE_DIRECTORY_INVALID'); }
    }

    private function readPrivate(string $path, int $maximum = 131072): string
    {
        clearstatcache(true, $path);
        $before = @lstat($path);
        if (is_link($path) || realpath($path) !== $path || !is_array($before) || ($before['mode'] & 0170000) !== 0100000
            || ($before['mode'] & 0777) !== 0600 || $before['uid'] !== posix_geteuid() || $before['nlink'] !== 1) { self::fail('PRIVATE_FILE_INVALID'); }
        $handle = @fopen($path, 'rb');
        if ($handle === false) { self::fail('PRIVATE_FILE_INVALID'); }
        try {
            $opened = fstat($handle);
            $fields = array_flip(['dev', 'ino', 'mode', 'nlink', 'uid', 'gid', 'size', 'mtime', 'ctime']);
            if (!is_array($opened) || $opened['size'] > $maximum
                || array_intersect_key($opened, $fields) !== array_intersect_key($before, $fields)) { self::fail('PRIVATE_FILE_CHANGED'); }
            $bytes = stream_get_contents($handle, $maximum + 1);
            $after = fstat($handle);
            clearstatcache(true, $path);
            $atPath = @lstat($path);
            if (!is_string($bytes) || $bytes === '' || strlen($bytes) !== $opened['size'] || !is_array($after)
                || !is_array($atPath) || array_intersect_key($atPath, $fields) !== array_intersect_key($opened, $fields)
                || array_intersect_key($after, $fields) !== array_intersect_key($opened, $fields)) { self::fail('PRIVATE_FILE_CHANGED'); }
            return $bytes;
        } finally { fclose($handle); }
    }

    private function createPrivate(string $path, string $bytes): void
    {
        $old = umask(0077);
        try { $handle = @fopen($path, 'xb'); }
        finally { umask($old); }
        if ($handle === false) { self::fail('EXISTING_OPERATION_REQUIRES_RECONCILIATION'); }
        try {
            if (fwrite($handle, $bytes) !== strlen($bytes) || !fflush($handle) || !fsync($handle)) { self::fail('PRIVATE_WRITE_OUTCOME_UNKNOWN'); }
        } finally { fclose($handle); }
        $directory = fopen(dirname($path), 'r');
        if ($directory === false) { self::fail('PRIVATE_WRITE_OUTCOME_UNKNOWN'); }
        try { if (!fsync($directory)) { self::fail('PRIVATE_WRITE_OUTCOME_UNKNOWN'); } }
        finally { fclose($directory); }
    }

    private function encode(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n";
    }

    private static function fail(string $code): never
    {
        throw new RuntimeException('IDENTITY_RECOVERY_CURRENT_' . $code);
    }
}
