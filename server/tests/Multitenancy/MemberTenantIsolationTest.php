<?php

declare(strict_types=1);

use PeanutAdmin\Modules\Member\Value\AccountLogEnum;
use PeanutAdmin\Modules\Member\Model\Member;
use PeanutAdmin\Modules\Member\Model\MemberBalanceLog;
use PeanutAdmin\Modules\Member\Model\MemberTag;
use PeanutAdmin\Modules\Member\Model\MemberTagRelation;
use PeanutAdmin\Modules\Member\Service\MemberProfileContractService;
use PeanutAdmin\Modules\Member\Service\MemberTagContractService;
use PeanutAdmin\Modules\Member\Service\MemberBalanceService;
use app\common\execution\CurrentExecutionContext;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Auth\ValidatedTenantSession;

require_once __DIR__ . '/../Support/CiBootstrap.php';
require __DIR__ . '/../Support/IsolatedBackendEnvironment.php';
require_once __DIR__ . '/../Support/IdentityMysqlFixture.php';
require_once __DIR__ . '/../Support/ThinkPhpTestConnection.php';
require_once __DIR__ . '/../Support/RegisteredMysqlTestResource.php';

function expectMemberTenant(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function memberTenantContext(int $tenantId, int $memberId, string $requestId): TenantContext
{
    return TenantContext::fromValidatedSession(new ValidatedTenantSession(
        $memberId,
        '01JMT03MEMBER' . str_pad((string) $memberId, 13, '0', STR_PAD_LEFT),
        $tenantId,
        $memberId + 10000,
        $memberId,
        'admin-web',
        new DateTimeImmutable('2031-01-01T00:00:00Z'),
        1,
        1,
    ), $requestId);
}

function memberTenantRun(TenantContext $context, callable $callback): mixed
{
    return app(\app\common\execution\ExecutionContextStore::class)->run(
        new \app\common\execution\AdminExecutionContext($context, 'test.member.tenant'),
        $callback,
    );
}

function createMemberTenantSchema(PDO $pdo): void
{
    IdentityMysqlFixture::install($pdo, [
        dirname(__DIR__, 2) . '/app/modules/peanut/member/database/migrations',
    ]);
}

$serverRoot = dirname(__DIR__, 2);
$host = IsolatedBackendEnvironment::required('DB_HOST');
$port = (int) IsolatedBackendEnvironment::required('DB_PORT');
$database = IsolatedBackendEnvironment::required('DB_NAME');
$user = IsolatedBackendEnvironment::required('DB_USER');
$password = IsolatedBackendEnvironment::required('DB_PASS');
$runId = strtolower(bin2hex(random_bytes(5)));
$database = RegisteredMysqlTestResource::configuredDatabaseName();
[$pdo, $createdDatabase] = RegisteredMysqlTestResource::openEmptyDatabase($database);

try {
    IsolatedBackendEnvironment::activateDatabase($host, $port, $database, $user, $password, 'multi-tenant');
    $fixtureApp = new think\App(dirname(__DIR__, 2));
    $fixtureApp->initialize();
    ThinkPhpTestConnection::fromPdo($pdo);
    createMemberTenantSchema($pdo);
    $pdo->exec("INSERT INTO pa_tenant (id,code,name,display_name,status,created_at,updated_at) VALUES (101,'alpha','Alpha','Alpha','active',UTC_TIMESTAMP(3),UTC_TIMESTAMP(3)),(202,'beta','Beta','Beta','active',UTC_TIMESTAMP(3),UTC_TIMESTAMP(3))");
    $pdo->exec(<<<'SQL'
INSERT INTO pa_member
  (id, tenant_id, sn, account, password, nickname, mobile, status, user_money, create_time, update_time)
VALUES
  (11, 101, 'M-SHARED-11', 'same-account', '', 'Alpha member', '13800000000', 1, 10.00, 1, 1);
INSERT INTO pa_member_tag (id, tenant_id, name, remark, create_time, update_time)
VALUES (21, 101, 'same-tag', 'alpha', 1, 1);
INSERT INTO pa_member_tag_relation (id, tenant_id, member_id, tag_id) VALUES (31, 101, 11, 21);
INSERT INTO pa_member_balance_log
  (id, tenant_id, sn, member_id, change_object, change_type, action, change_amount, left_amount, source_type, source_sn, remark, admin_id, create_time)
VALUES
  (41, 101, 'FLOW-SAME', 11, 1, 100, 1, 10.00, 10.00, 0, 'SOURCE-SAME', 'alpha', 0, 1);
SQL);
    IsolatedBackendEnvironment::activateDatabase($host, $port, $database, $user, $password, 'multi-tenant');
    $app = new think\App();
    $app->initialize();
    ThinkPhpTestConnection::fromPdo($pdo);
    set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
        throw new ErrorException($message, 0, $severity, $file, $line);
    });
    set_exception_handler(static function (Throwable $exception): never {
        fwrite(STDERR, $exception::class . ': ' . $exception->getMessage() . PHP_EOL);
        exit(1);
    });
    $alpha = memberTenantContext(101, 501, 'mt03-member-alpha-' . $runId);
    $beta = memberTenantContext(202, 502, 'mt03-member-beta-' . $runId);
    try {
        app(CurrentExecutionContext::class)->tenantAdmin();
        throw new RuntimeException('missing TenantContext was accepted');
    } catch (Throwable $e) {
        expectMemberTenant($e->getMessage() !== '', 'missing context denial lost shape');
    }

    try {
        memberTenantRun($beta, static fn() => Member::create(['tenant_id' => 101, 'sn' => 'forged-owner']));
        throw new RuntimeException('member payload forged tenant ownership');
    } catch (DomainException $exception) {
        expectMemberTenant($exception->getMessage() === 'TENANT_WRITE_OWNERSHIP_MISMATCH', 'forged member owner denial changed');
    }
    $betaMember = memberTenantRun($beta, static fn() => Member::create([
        'sn' => 'M-SHARED-11', 'account' => 'same-account', 'nickname' => 'Beta member',
        'mobile' => '13800000000', 'status' => 1, 'user_money' => 20,
    ]));
    expectMemberTenant((int) $betaMember->tenant_id === 202, 'member payload forged Tenant ownership');
    memberTenantRun($beta, static fn() => (new MemberTagContractService())->create($beta, 'same-tag', ''));
    $betaTag = (int) memberTenantRun($beta, static fn() => MemberTag::where([])->where('name', 'same-tag')->value('id'));
    expectMemberTenant($betaTag > 0, 'same-name tag was not tenant-scoped');
    memberTenantRun($beta, static fn() => MemberTagRelation::create([
        'tenant_id' => 202, 'member_id' => (int) $betaMember->id, 'tag_id' => $betaTag,
    ]));
    memberTenantRun($beta, static fn() => MemberBalanceLog::create([
        'sn' => MemberBalanceLog::generateSn($beta),
        'member_id' => (int) $betaMember->id,
        'change_object' => AccountLogEnum::getChangeObject(AccountLogEnum::USER_MONEY_INC_ADMIN),
        'change_type' => AccountLogEnum::USER_MONEY_INC_ADMIN,
        'action' => AccountLogEnum::INC,
        'change_amount' => 1.00,
        'left_amount' => 20.00,
        'source_type' => 0,
        'source_sn' => 'SOURCE-SAME',
        'remark' => '',
        'admin_id' => 0,
    ]));
    $alphaMember = (int) memberTenantRun($alpha, static fn() => Member::where([])->where('account', 'same-account')->value('id'));
    expectMemberTenant($alphaMember === 11, 'Alpha same-account member disappeared');
    expectMemberTenant(memberTenantRun($alpha, static fn() => Member::where([])->where('id', (int) $betaMember->id)->findOrEmpty()->isEmpty()), 'Alpha read Beta member');
    try {
        memberTenantRun($alpha, static fn() => (new MemberProfileContractService())->updateStatus($alpha, (int) $betaMember->id, 0));
        throw new RuntimeException('Alpha updated Beta member');
    } catch (Throwable $e) {
        expectMemberTenant($e->getMessage() !== '', 'cross-Tenant status denial lost shape');
    }
    expectMemberTenant((int) memberTenantRun($beta, static fn() => Member::where([])->where('id', (int) $betaMember->id)->value('status')) === 1, 'cross-Tenant status denial mutated Beta');

    $alphaBefore = (string) memberTenantRun($alpha, static fn() => Member::where([])->where('id', 11)->value('user_money'));
    $betaBefore = (string) memberTenantRun($beta, static fn() => Member::where([])->where('id', (int) $betaMember->id)->value('user_money'));
    memberTenantRun($alpha, static function () use ($alpha): void {
        think\facade\Db::transaction(static fn() => MemberBalanceService::applyInTransaction(
            $alpha,
            11,
            AccountLogEnum::USER_MONEY_INC_ADMIN,
            AccountLogEnum::INC,
            100,
            'SOURCE-ALPHA-ADJUST',
            'alpha',
        ));
    });
    expectMemberTenant((string) memberTenantRun($alpha, static fn() => Member::where([])->where('id', 11)->value('user_money')) !== $alphaBefore, 'Alpha balance did not change');
    expectMemberTenant((string) memberTenantRun($beta, static fn() => Member::where([])->where('id', (int) $betaMember->id)->value('user_money')) === $betaBefore, 'Alpha adjustment changed Beta balance');
    expectMemberTenant((int) memberTenantRun($alpha, static fn() => MemberBalanceLog::where([])->count()) === 2, 'Alpha admin ledger leaked or lost rows');
    expectMemberTenant((int) memberTenantRun($beta, static fn() => MemberBalanceLog::where([])->where('member_id', (int) $betaMember->id)->count()) === 1, 'Beta API ledger leaked or lost rows');

    try {
        memberTenantRun($alpha, static fn() => (new MemberTagContractService())->delete($alpha, $betaTag));
        throw new RuntimeException('Alpha deleted Beta tag');
    } catch (Throwable $e) {
        expectMemberTenant($e->getMessage() !== '', 'cross-Tenant tag denial lost shape');
    }
    expectMemberTenant(memberTenantRun($beta, static fn() => !MemberTag::where([])->where('id', $betaTag)->findOrEmpty()->isEmpty()), 'Beta tag changed after Alpha cleanup');
    expectMemberTenant((int) memberTenantRun($beta, static fn() => MemberTagRelation::where([])->where('tag_id', $betaTag)->count()) === 1, 'Alpha cleanup changed Beta relation');
    expectMemberTenant((int) memberTenantRun($alpha, static fn() => MemberBalanceLog::where([])->where('source_sn', 'SOURCE-SAME')->count()) === 1, 'Alpha source_sn scope mismatch');
    expectMemberTenant((int) memberTenantRun($beta, static fn() => MemberBalanceLog::where([])->where('source_sn', 'SOURCE-SAME')->count()) === 1, 'Beta source_sn scope mismatch');
    try {
        memberTenantRun($alpha, static fn() => MemberBalanceLog::create([
            'sn' => MemberBalanceLog::generateSn($alpha),
            'member_id' => 11,
            'change_object' => AccountLogEnum::getChangeObject(AccountLogEnum::USER_MONEY_INC_ADMIN),
            'change_type' => AccountLogEnum::USER_MONEY_INC_ADMIN,
            'action' => AccountLogEnum::INC,
            'change_amount' => 1.00,
            'left_amount' => 110.00,
            'source_type' => 0,
            'source_sn' => 'SOURCE-SAME',
            'remark' => '',
            'admin_id' => 0,
        ]));
        throw new RuntimeException('same-Tenant source_sn uniqueness was lowered');
    } catch (Throwable $e) {
        $errorInfo = $e instanceof think\db\exception\PDOException
            ? ($e->getData()['PDO Error Info'] ?? [])
            : [];
        expectMemberTenant(
            ($errorInfo['SQLSTATE'] ?? '') === '23000'
                && (int) ($errorInfo['Driver Error Code'] ?? 0) === 1062,
            'same-Tenant source_sn did not fail with SQLSTATE 23000 / MySQL 1062',
        );
    }
    expectMemberTenant(
        (int) memberTenantRun($alpha, static fn() => MemberBalanceLog::where([])->where('source_sn', 'SOURCE-SAME')->count()) === 1,
        'duplicate same-Tenant source_sn row was inserted',
    );

    foreach (['modules/peanut/member/src/Service/MemberAdministrationService.php', 'modules/peanut/member/src/Service/MemberTagContractService.php', 'modules/peanut/member/src/Service/MemberBalanceService.php', 'modules/peanut/member/src/Model/MemberBalanceLog.php'] as $relative) {
        exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($serverRoot . '/app/' . $relative), $output, $exit);
        expectMemberTenant($exit === 0, 'PHP 8.3 lint failed: ' . $relative . ' ' . implode(' ', $output));
        $output = [];
    }
} finally {
    RegisteredMysqlTestResource::cleanup($pdo, $database, $createdDatabase);
}

echo "MT03-MEMBER-TENANT-001 passed\n";
