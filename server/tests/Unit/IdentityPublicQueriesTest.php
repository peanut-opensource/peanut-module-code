<?php

declare(strict_types=1);

namespace tests\Unit;

use app\common\execution\CurrentExecutionContext;
use app\common\execution\ExecutionContextStore;
use DateTimeImmutable;
use PDO;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Auth\ValidatedTenantSession;
use PeanutAdmin\Modules\Identity\Contract\AdminDirectoryQuery;
use PeanutAdmin\Modules\Identity\Contract\TenantAuthorizationQuery;
use PeanutAdmin\Modules\Identity\Membership\Query\ThinkPhpTenantMemberDirectory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use think\App;

require_once dirname(__DIR__) . '/Support/ThinkPhpTestConnection.php';

/** 使用真实 ThinkORM 与进程内合成表验证公开投影，不替代 MySQL、HTTP 或并发验收。 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class IdentityPublicQueriesTest extends TestCase
{
    private PDO $database;
    private AdminDirectoryQuery $directory;
    private TenantAuthorizationQuery $authorization;

    protected function setUp(): void
    {
        $root = dirname(__DIR__, 3);
        $temporaryRoot = $root . '/.local/tmp/identity-public-query-tests';
        if (!is_dir($temporaryRoot) && !mkdir($temporaryRoot, 0700, true) && !is_dir($temporaryRoot)) {
            throw new \RuntimeException('IDENTITY_QUERY_TEST_DIRECTORY_UNAVAILABLE');
        }
        $temporary = realpath($temporaryRoot);
        self::assertIsString($temporary);
        self::assertStringStartsWith($root . '/.local/tmp/', $temporary);
        require_once $root . '/server/vendor/topthink/framework/src/helper.php';
        new App($temporary . '/identity-query-' . bin2hex(random_bytes(6)));
        $this->database = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        \ThinkPhpTestConnection::fromPdo($this->database);
        $this->database->exec(<<<'SQL'
            CREATE TABLE pa_tenant (id INTEGER PRIMARY KEY, name TEXT, status TEXT, security_revision INTEGER, authorization_revision INTEGER);
            CREATE TABLE pa_account (id INTEGER PRIMARY KEY, display_name TEXT, status TEXT, security_revision INTEGER, avatar_uri TEXT, last_login_at TEXT);
            CREATE TABLE pa_tenant_member (id INTEGER PRIMARY KEY, tenant_id INTEGER, account_id INTEGER, display_name TEXT, primary_department_id INTEGER, status TEXT, security_revision INTEGER, authorization_revision INTEGER);
            CREATE TABLE pa_role (id INTEGER PRIMARY KEY, tenant_id INTEGER, "key" TEXT, name TEXT, is_builtin INTEGER, status TEXT);
            CREATE TABLE pa_member_role (tenant_id INTEGER, tenant_member_id INTEGER, role_id INTEGER);
            CREATE TABLE pa_permission (id INTEGER PRIMARY KEY, module_key TEXT, "key" TEXT, status TEXT);
            CREATE TABLE pa_role_permission (tenant_id INTEGER, role_id INTEGER, permission_id INTEGER);
            CREATE TABLE pa_credential (id INTEGER PRIMARY KEY, account_id INTEGER, kind TEXT, identifier_type TEXT, identifier_normalized TEXT, status TEXT);
            CREATE TABLE pa_tenant_session (id INTEGER PRIMARY KEY, tenant_id INTEGER, account_id INTEGER, tenant_member_id INTEGER, session_key TEXT, status TEXT, account_security_revision INTEGER, tenant_security_revision INTEGER, member_security_revision INTEGER, idle_expires_at TEXT, absolute_expires_at TEXT);
            INSERT INTO pa_tenant VALUES (1, '合成甲', 'active', 1, 1), (2, '合成乙', 'active', 1, 1);
            INSERT INTO pa_account VALUES (101, '甲所有者', 'active', 1, 'avatar-a', NULL), (202, '乙所有者', 'active', 1, 'avatar-b', NULL);
            INSERT INTO pa_tenant_member VALUES (11, 1, 101, '甲所有者', NULL, 'active', 1, 3), (22, 2, 202, '乙所有者', NULL, 'active', 1, 3);
            INSERT INTO pa_role VALUES (31, 1, 'core.tenant-owner', '所有者', 1, 'active'), (32, 2, 'core.tenant-owner', '所有者', 1, 'active');
            INSERT INTO pa_member_role VALUES (1, 11, 31), (2, 22, 32);
            INSERT INTO pa_permission VALUES (41, 'peanut.admin', 'article.edit', 'active'), (42, 'peanut.admin', 'article.delete', 'inactive'), (43, 'official.article', 'article.read', 'active');
            INSERT INTO pa_role_permission VALUES (1, 31, 41), (1, 31, 42), (1, 31, 43), (2, 32, 41);
            INSERT INTO pa_credential VALUES (51, 101, 'email_password', 'email', 'owner-a@example.test', 'active'), (52, 202, 'email_password', 'email', 'owner-b@example.test', 'active');
            INSERT INTO pa_tenant_session VALUES (61, 1, 101, 11, 'synthetic-a', 'active', 1, 1, 1, '2099-01-01 00:00:00.000', '2099-01-01 00:00:00.000'), (62, 2, 202, 22, 'synthetic-b', 'active', 1, 1, 1, '2099-01-01 00:00:00.000', '2099-01-01 00:00:00.000');
            SQL);
        $this->directory = new AdminDirectoryQuery(new CurrentExecutionContext(new ExecutionContextStore()));
        $registry = new \PeanutAdmin\Kernel\Module\CompiledModuleRegistry([], [], [], [], 'identity-query');
        $this->authorization = new TenantAuthorizationQuery(new ThinkPhpTenantMemberDirectory(), $registry);
    }

    private function actor(int $tenantId = 1, int $memberId = 11, int $accountId = 101): TenantContext
    {
        return TenantContext::fromValidatedSession(new ValidatedTenantSession(
            61,
            'synthetic-a',
            $tenantId,
            $accountId,
            $memberId,
            'test',
            new DateTimeImmutable('2099-01-01T00:00:00Z'),
            3,
            1,
        ), 'identity-query-test');
    }

    public function testOwnerProjectionConvertsAnActualThinkOrmModelIntoStableData(): void
    {
        self::assertSame(['id' => 11, 'account_id' => 101, 'authorization_revision' => 3, 'tenant_authorization_revision' => 1], $this->directory->activeTenantOwner(1, 11, 101));
        self::assertSame(['id' => 22, 'account_id' => 202, 'authorization_revision' => 3, 'tenant_authorization_revision' => 1], $this->directory->activeTenantOwner(2, null, null));
    }

    public function testOwnerSelectionDoesNotTreatPartialOrForeignIdentityAsAnyOwner(): void
    {
        self::assertNull($this->directory->activeTenantOwner(1, 11, null));
        self::assertNull($this->directory->activeTenantOwner(1, null, 101));
        self::assertNull($this->directory->activeTenantOwner(1, 22, 202));
        self::assertNull($this->directory->activeTenantOwner(1, 11, 202));
        self::assertNull($this->directory->activeTenantOwner(0, null, null));
    }

    public function testOwnerProjectionRemainsUsableByTrustedWorkersWithoutAnEmployeeSession(): void
    {
        $this->database->exec("UPDATE pa_tenant_session SET status = 'revoked'");
        self::assertSame(11, $this->directory->activeTenantOwner(1, 11, 101)['id'] ?? null);
        self::assertFalse($this->authorization->isTenantOwner($this->actor()));
    }

    public static function inactiveOwnerStates(): array
    {
        return [
            'tenant suspended' => ["UPDATE pa_tenant SET status = 'suspended' WHERE id = 1"],
            'account disabled' => ["UPDATE pa_account SET status = 'disabled' WHERE id = 101"],
            'member disabled' => ["UPDATE pa_tenant_member SET status = 'disabled' WHERE id = 11"],
            'role disabled' => ["UPDATE pa_role SET status = 'disabled' WHERE id = 31"],
            'owner membership revoked' => ['DELETE FROM pa_member_role WHERE tenant_id = 1'],
            'role is not builtin' => ['UPDATE pa_role SET is_builtin = 0 WHERE id = 31'],
        ];
    }

    #[DataProvider('inactiveOwnerStates')]
    public function testOwnerProjectionRejectsInactiveOrRevokedOwnership(string $mutation): void
    {
        self::assertNotNull($this->directory->activeTenantOwner(1, 11, 101));
        $this->database->exec($mutation);
        self::assertNull($this->directory->activeTenantOwner(1, 11, 101));
    }

    public function testPrincipalProfileIsScopedAndReturnsOnlyItsDeclaredShape(): void
    {
        self::assertEquals([
            'tenant_name' => '合成甲',
            'username' => 'owner-a@example.test',
            'avatar' => 'avatar-a',
            'last_login_at' => null,
            'member' => new \PeanutAdmin\Modules\Identity\Contract\Dto\TenantMemberSummary(1, 11, 101, '甲所有者', 1, 1, 1, 3, 1),
        ], $this->directory->activePrincipalProfile(1, 101));
        self::assertNull($this->directory->activePrincipalProfile(2, 101));
        $this->database->exec("UPDATE pa_credential SET status = 'revoked' WHERE id = 51");
        self::assertNull($this->directory->activePrincipalProfile(1, 101));
    }

    public function testPermissionProjectionRequiresActiveNativeSessionAndReturnsOnlyActiveApplicationPermissions(): void
    {
        self::assertTrue($this->authorization->isTenantOwner($this->actor()));
        self::assertSame(['article.edit'], $this->authorization->applicationPermissionKeys($this->actor()));
        self::assertSame(['article.edit'], $this->authorization->registeredPermissionKeys(['peanut.admin']));
    }

    public function testBatchRoleMemberCountsPreserveZerosAndTenantIsolation(): void
    {
        $this->database->exec('INSERT INTO pa_member_role VALUES (1, 12, 31), (2, 23, 31)');
        self::assertSame([31 => 2, 32 => 0, 999 => 0], $this->authorization->batchRoleMemberCounts(1, [31, 32, 999, 31]));
        self::assertSame([31 => 1, 32 => 1], $this->authorization->batchRoleMemberCounts(2, [31, 32]));
        self::assertSame([], $this->authorization->batchRoleMemberCounts(1, []));
        foreach ([31, 32, 999] as $roleId) {
            self::assertSame($this->authorization->roleMemberCount(1, $roleId), $this->authorization->batchRoleMemberCounts(1, [$roleId])[$roleId]);
        }
    }

    public static function invalidBatchRoleIds(): array
    {
        return ['zero' => [0], 'negative' => [-1], 'numeric string' => ['31'], 'float' => [31.0], 'array' => [[]]];
    }

    #[DataProvider('invalidBatchRoleIds')]
    public function testBatchRoleCountsRejectInvalidIdsRatherThanCoercingThem(mixed $roleId): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('ROLE_ID_INVALID');
        $this->authorization->batchRoleMemberCounts(1, [31, $roleId]);
    }

    public static function revokedActorStates(): array
    {
        return [
            'account disabled' => ["UPDATE pa_account SET status = 'disabled' WHERE id = 101"],
            'tenant suspended' => ["UPDATE pa_tenant SET status = 'suspended' WHERE id = 1"],
            'member disabled' => ["UPDATE pa_tenant_member SET status = 'disabled' WHERE id = 11"],
            'authorization changed' => ['UPDATE pa_tenant_member SET authorization_revision = 4 WHERE id = 11'],
            'account security changed' => ['UPDATE pa_account SET security_revision = 2 WHERE id = 101'],
            'tenant security changed' => ['UPDATE pa_tenant SET security_revision = 2 WHERE id = 1'],
            'member security changed' => ['UPDATE pa_tenant_member SET security_revision = 2 WHERE id = 11'],
            'session revoked' => ["UPDATE pa_tenant_session SET status = 'revoked' WHERE id = 61"],
            'idle timeout' => ["UPDATE pa_tenant_session SET idle_expires_at = '2000-01-01' WHERE id = 61"],
            'absolute timeout' => ["UPDATE pa_tenant_session SET absolute_expires_at = '2000-01-01' WHERE id = 61"],
            'role disabled' => ["UPDATE pa_role SET status = 'disabled' WHERE id = 31"],
            'membership removed' => ['DELETE FROM pa_member_role WHERE tenant_id = 1'],
        ];
    }

    #[DataProvider('revokedActorStates')]
    public function testPermissionAndOwnerQueriesObserveRevocationOnEveryCall(string $mutation): void
    {
        $actor = $this->actor();
        self::assertTrue($this->authorization->isTenantOwner($actor));
        self::assertSame(['article.edit'], $this->authorization->applicationPermissionKeys($actor));
        $this->database->exec($mutation);
        self::assertFalse($this->authorization->isTenantOwner($actor));
        self::assertSame([], $this->authorization->applicationPermissionKeys($actor));
    }

    public function testMismatchedAccountAndCrossTenantContextNeverReuseOwnerPrivileges(): void
    {
        foreach ([$this->actor(1, 11, 202), $this->actor(2, 11, 101), $this->actor(1, 22, 202)] as $actor) {
            self::assertFalse($this->authorization->isTenantOwner($actor));
            self::assertSame([], $this->authorization->applicationPermissionKeys($actor));
        }
    }
}
