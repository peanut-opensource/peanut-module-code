<?php

declare(strict_types=1);

use app\common\execution\CurrentExecutionContext;
use app\common\execution\ExecutionContextStore;
use PeanutAdmin\Kernel\Context\PlatformContext;
use PeanutAdmin\Modules\File\Contract\StorageQualificationQueries;
use PeanutAdmin\Modules\Identity\Contract\AdminDirectoryQuery;
use PeanutAdmin\Modules\Integration\Contract\ExternalBindingQualificationQueries;
use PeanutAdmin\Modules\Ops\Domain\Application\OpsConsoleException;
use PeanutAdmin\Modules\Ops\Domain\Application\PlatformPermissionChecker;
use PeanutAdmin\Modules\Ops\Service\ProviderQualificationProjection;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/Support/ThinkPhpTestConnection.php';

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class OpsProviderQualificationTest extends TestCase
{
    public function testPagesUseOnlyCurrentScopeAndPreserveUnverifiedSemantics(): void
    {
        [$file, $tenants, $bindings] = $this->fixture();
        $calls = [];
        $projection = new ProviderQualificationProjection(
            $this->permission(true),
            static fn (string $module): bool => in_array($module, ['peanut.file', 'peanut.integration', 'peanut.notification'], true),
            static fn (): string => str_repeat('k', 32),
            $file->countSubjects(...),
            static function (string $key, int $offset, int $limit) use ($file, &$calls): array {
                $calls[] = ['storage', $offset, $limit];
                return $file->subjects($key, $offset, $limit);
            },
            $tenants->countActiveTenantsForQualification(...),
            static function (int $offset, int $limit) use ($tenants, &$calls): array {
                $calls[] = ['tenants', $offset, $limit];
                return $tenants->activeTenantIdsForQualification($offset, $limit);
            },
            static function (array $ids, array $providers, string $key) use ($bindings, &$calls): array {
                $calls[] = ['bindings', $ids, $providers];
                return $bindings->forTenants($ids, $providers, $key);
            },
        );

        $first = $projection->snapshot($this->context(), 1, 3);
        self::assertSame(8, $first['total']);
        self::assertSame(['storage.local', 'storage.qiniu', 'notification.email'], array_column($first['providers'], 'provider_key'));
        self::assertSame(['CONNECTIVITY_EVIDENCE_REQUIRED', 'CONFIGURATION_REQUIRED', 'NOT_IMPLEMENTED'], array_column($first['providers'], 'status_code'));
        self::assertSame([['storage', 0, 2], ['tenants', 0, 1]], $calls);

        $calls = [];
        $second = $projection->snapshot($this->context(), 2, 3);
        self::assertSame(8, $second['total']);
        self::assertSame([
            ['tenants', 1, 1],
            ['tenants', 0, 2],
            ['bindings', [2, 7], ['notification.sms.aliyun']],
        ], $calls);
        self::assertSame([false, true, false], array_column($second['providers'], 'configured'));
        foreach (array_merge($first['providers'], $second['providers']) as $item) {
            self::assertMatchesRegularExpression('/^scope_[a-f0-9]{64}$/D', $item['scope']['key']);
            self::assertSame(hash('sha256', ''), $item['evidence_digest']);
            self::assertFalse($item['connected']);
            self::assertFalse($item['callback_verified']);
            self::assertFalse($item['qualified']);
            self::assertNull($item['observed_at']);
            self::assertNull($item['expires_at']);
            self::assertNull($item['recent_failure']);
        }
        $public = json_encode([$first, $second], JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('private-bucket', $public);
        self::assertStringNotContainsString('secret-ciphertext', $public);
        self::assertStringNotContainsString('config_digest', $public);

        $calls = [];
        $empty = $projection->snapshot($this->context(), 4, 3);
        self::assertSame([], $empty['providers']);
        self::assertSame(8, $empty['total']);
        self::assertSame([], $calls);
    }

    public function testMissingDeploymentDoesNotReadOptionalContracts(): void
    {
        $never = static function (): never { self::fail('An unavailable source was read'); };
        $projection = new ProviderQualificationProjection(
            $this->permission(true), static fn (): bool => false, $never, $never, $never, $never, $never, $never,
        );
        $snapshot = $projection->snapshot($this->context(), 1, 20);
        self::assertSame([], $snapshot['providers']);
        self::assertSame(0, $snapshot['total']);
    }

    public function testDeniedRequestDoesNotCheckDeploymentOrReadSources(): void
    {
        $never = static function (): never { self::fail('A denied request entered a data source'); };
        $projection = new ProviderQualificationProjection(
            $this->permission(false), $never, $never, $never, $never, $never, $never, $never,
        );
        try {
            $projection->snapshot($this->context(), 1, 20);
            self::fail('Expected the platform permission boundary');
        } catch (OpsConsoleException $error) {
            self::assertSame('OPS_PERMISSION_DENIED', $error->problemCode);
            self::assertSame(403, $error->status);
        }
    }

    public function testFailureOrShortPageIsNotPublishedAsAnEmptySuccess(): void
    {
        $failures = [
            static fn (): array => [],
            static function (): never { throw new RuntimeException('private provider diagnostic'); },
        ];
        foreach ($failures as $storagePage) {
            $never = static function (): never { self::fail('No tenant source is deployed'); };
            $projection = new ProviderQualificationProjection(
                $this->permission(true),
                static fn (string $key): bool => $key === 'peanut.file',
                static fn (): string => str_repeat('k', 32),
                static fn (): int => 1,
                $storagePage, $never, $never, $never,
            );
            try {
                $projection->snapshot($this->context(), 1, 20);
                self::fail('Expected an explicit provider failure');
            } catch (OpsConsoleException $error) {
                self::assertSame('OPS_PROVIDER_UNAVAILABLE', $error->getMessage());
                self::assertSame(503, $error->status);
            }
        }
    }

    public function testFileAndIdentityQueriesRetainLegacyCallsAndReturnStableWindows(): void
    {
        [$file, $tenants] = $this->fixture();
        self::assertSame(2, $tenants->countActiveTenantsForQualification());
        self::assertSame([2, 7], $tenants->activeTenantIdsForQualification());
        self::assertSame([7], $tenants->activeTenantIdsForQualification(1, 1));
        self::assertSame([], $tenants->activeTenantIdsForQualification(20, 1));

        $digestKey = str_repeat('k', 32);
        self::assertSame(2, $file->countSubjects());
        $subjects = $file->subjects($digestKey);
        self::assertSame(['private-bucket-1', 'private-bucket-2'], array_column($subjects, 'account_key'));
        self::assertSame([$subjects[1]], $file->subjects($digestKey, 1, 1));
        self::assertSame([], $file->subjects($digestKey, 20, 1));
    }

    private function permission(bool $allowed): PlatformPermissionChecker
    {
        return new class ($allowed) implements PlatformPermissionChecker {
            public function __construct(private readonly bool $allowed) {}
            public function allows(PlatformContext $context, string $permissionKey): bool
            {
                TestCase::assertSame('platform.ops.read', $permissionKey);
                return $this->allowed;
            }
        };
    }

    private function context(): PlatformContext
    {
        return PlatformContext::fromTrustedAutomation(1, 1, 'test', 'ops-provider-test', new DateTimeImmutable());
    }

    private function fixture(): array
    {
        $pdo = new PDO('sqlite::memory:');
        ThinkPhpTestConnection::fromPdo($pdo);
        $pdo->exec(<<<'SQL'
            CREATE TABLE pa_tenant (id INTEGER PRIMARY KEY, status TEXT);
            INSERT INTO pa_tenant VALUES (7,'active'),(3,'disabled'),(2,'active');
            CREATE TABLE pa_storage_account (id INTEGER PRIMARY KEY, account_key TEXT, driver TEXT, credential_ciphertext TEXT,
                credential_key_version TEXT, credential_rotated_at TEXT, status TEXT, updated_at TEXT);
            INSERT INTO pa_storage_account VALUES
                (8,'private-bucket-2','qiniu','secret-ciphertext','v1','2026-01-01 00:00:00','active','2026-01-01'),
                (4,'private-bucket-1','local','','',NULL,'active','2026-01-01');
            CREATE TABLE pa_storage_space (id INTEGER PRIMARY KEY, account_id INTEGER, status TEXT, updated_at TEXT);
            INSERT INTO pa_storage_space VALUES (1,4,'active','2026-01-01');
            CREATE TABLE pa_external_channel_binding (id INTEGER PRIMARY KEY, tenant_id INTEGER, provider TEXT, identity_hash TEXT,
                config_json TEXT, status INTEGER, update_time TEXT, retired_at TEXT);
            INSERT INTO pa_external_channel_binding (id,tenant_id,provider,identity_hash,config_json,status,update_time) VALUES
                (1,2,'notice.sms','fixture','{"sms_aliyun":{"status":1,"access_key_id":"fixture","access_key_secret":"fixture","sign_name":"fixture"}}',1,'2026-01-01');
            SQL);
        return [
            new StorageQualificationQueries(),
            new AdminDirectoryQuery(new CurrentExecutionContext(new ExecutionContextStore())),
            new ExternalBindingQualificationQueries(),
        ];
    }
}
