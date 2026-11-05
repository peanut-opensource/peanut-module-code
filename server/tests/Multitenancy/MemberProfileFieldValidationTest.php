<?php

declare(strict_types=1);

use app\common\execution\ConsumerExecutionContext;
use app\common\execution\ExecutionContextStore;
use app\common\exception\BusinessException;
use PeanutAdmin\Modules\Member\Model\Member;
use PeanutAdmin\Modules\Member\Service\MemberProfileContractService;
use PeanutAdmin\Kernel\Context\AuthenticatedMemberContext;

require_once __DIR__ . '/../Support/CiBootstrap.php';
require __DIR__ . '/../Support/IsolatedBackendEnvironment.php';
require_once __DIR__ . '/../Support/IdentityMysqlFixture.php';
require_once __DIR__ . '/../Support/ThinkPhpTestConnection.php';
require_once __DIR__ . '/../Support/RegisteredMysqlTestResource.php';

$memberProfileAssertions = 0;

function expectMemberProfile(bool $condition, string $message): void
{
    global $memberProfileAssertions;
    $memberProfileAssertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function memberProfileContext(int $tenantId, int $memberId, string $requestId): AuthenticatedMemberContext
{
    return new AuthenticatedMemberContext(
        $tenantId,
        $memberId,
        hash('sha256', 'member-profile-' . $tenantId . '-' . $memberId),
        $requestId,
    );
}

function memberProfileSchema(PDO $pdo): void
{
    IdentityMysqlFixture::install($pdo, [
        dirname(__DIR__, 2) . '/app/modules/peanut/member/database/migrations',
    ]);
}

function rejectMemberProfile(callable $operation, string $message): void
{
    try {
        $operation();
    } catch (BusinessException $exception) {
        expectMemberProfile($exception->errorCode === 'MEMBER_PROFILE_VALUE_INVALID', $message . ': error code changed');
        return;
    }

    throw new RuntimeException($message . ': invalid value was accepted');
}

$serverRoot = dirname(__DIR__, 2);
$host = IsolatedBackendEnvironment::required('DB_HOST');
$port = (int) IsolatedBackendEnvironment::required('DB_PORT');
$database = RegisteredMysqlTestResource::configuredDatabaseName();
$user = IsolatedBackendEnvironment::required('DB_USER');
$password = IsolatedBackendEnvironment::required('DB_PASS');
[$pdo, $createdDatabase] = RegisteredMysqlTestResource::openEmptyDatabase($database);

try {
    IsolatedBackendEnvironment::activateDatabase($host, $port, $database, $user, $password, 'multi-tenant');
    $fixtureApp = new think\App(dirname(__DIR__, 2));
    $fixtureApp->initialize();
    ThinkPhpTestConnection::fromPdo($pdo);
    memberProfileSchema($pdo);
    $pdo->exec("INSERT INTO pa_tenant (id,code,name,display_name,status,created_at,updated_at) VALUES (101,'alpha','Alpha','Alpha','active',UTC_TIMESTAMP(3),UTC_TIMESTAMP(3)),(202,'beta','Beta','Beta','active',UTC_TIMESTAMP(3),UTC_TIMESTAMP(3))");
    $pdo->exec(<<<'SQL'
INSERT INTO pa_member (id, tenant_id, sn, nickname, sex, birthday, email, status, create_time, update_time)
VALUES
  (501, 101, 'M-PROFILE-501', 'Alpha', 0, '2000-01-01', 'alpha@example.com', 1, 1, 1),
  (503, 101, 'M-PROFILE-503', 'Alpha peer', 0, '2002-01-01', 'peer@example.com', 1, 1, 1),
  (502, 202, 'M-PROFILE-502', 'Beta', 0, '2001-01-01', 'beta@example.com', 1, 1, 1)
SQL);

    IsolatedBackendEnvironment::activateDatabase($host, $port, $database, $user, $password, 'multi-tenant');
    $app = new think\App($serverRoot);
    $app->initialize();
    ThinkPhpTestConnection::fromPdo($pdo);
    $profile = new MemberProfileContractService();
    $alpha = memberProfileContext(101, 501, 'd02-profile-alpha');
    $beta = memberProfileContext(202, 502, 'd02-profile-beta');
    $contexts = $app->make(ExecutionContextStore::class);

    $contexts->run(ConsumerExecutionContext::member($alpha, 'test.member.profile.valid'), static function () use ($profile, $alpha): void {
        $profile->updateSelfField($alpha, 501, 'nickname', str_repeat('名', 50));
        $profile->updateSelfField($alpha, 501, 'sex', '2');
        $profile->updateSelfField($alpha, 501, 'birthday', '2000-02-29');
        $profile->updateSelfField($alpha, 501, 'email', str_repeat('a', 64) . '@' . str_repeat('b', 31) . '.com');
        $profile->updateSelfField($alpha, 501, 'avatar', str_repeat('a', 255));
    });
    expectMemberProfile(
        (string) $contexts->run(ConsumerExecutionContext::member($alpha, 'test.member.profile.read'), static fn() => Member::where([])->where('id', 501)->value('nickname')) === str_repeat('名', 50),
        'valid nickname was not persisted',
    );

    $contexts->run(ConsumerExecutionContext::member($alpha, 'test.member.profile.invalid'), static function () use ($profile, $alpha): void {
        rejectMemberProfile(static fn() => $profile->updateSelfField($alpha, 501, 'sex', '3'), 'invalid sex');
        rejectMemberProfile(static fn() => $profile->updateSelfField($alpha, 501, 'nickname', ['array']), 'array nickname');
        rejectMemberProfile(static fn() => $profile->updateSelfField($alpha, 501, 'nickname', str_repeat('a', 51)), 'long nickname');
        rejectMemberProfile(static fn() => $profile->updateSelfField($alpha, 501, 'avatar', str_repeat('a', 256)), 'long avatar');
        rejectMemberProfile(static fn() => $profile->updateSelfField($alpha, 501, 'email', 'not-an-email'), 'invalid email');
        rejectMemberProfile(static fn() => $profile->updateSelfField($alpha, 501, 'email', str_repeat('a', 89) . '@example.com'), 'long email');
        rejectMemberProfile(static fn() => $profile->updateSelfField($alpha, 501, 'birthday', '2025-02-29'), 'invalid birthday');
        rejectMemberProfile(static fn() => $profile->updateSelfField($alpha, 501, 'birthday', '0001-01-01'), 'out-of-range birthday');
        rejectMemberProfile(static fn() => $profile->updateSelfField($alpha, 501, 'birthday', "2000-01-01\0x"), 'null-byte birthday');
    });

    $contexts->run(ConsumerExecutionContext::member($alpha, 'test.member.profile.clear'), static function () use ($profile, $alpha): void {
        $profile->updateSelfField($alpha, 501, 'birthday', '');
        $profile->updateSelfField($alpha, 501, 'email', '');
        $profile->updateSelfField($alpha, 501, 'avatar', '');
    });
    expectMemberProfile(
        $contexts->run(ConsumerExecutionContext::member($alpha, 'test.member.profile.clear-read'), static fn() => Member::where([])->where('id', 501)->value('birthday')) === null,
        'empty birthday did not clear to NULL',
    );
    $contexts->run(ConsumerExecutionContext::member($alpha, 'test.member.profile.clear-repeat'), static function () use ($profile, $alpha): void {
        $profile->updateSelfField($alpha, 501, 'birthday', '');
    });
    try {
        $missing = memberProfileContext(101, 999, 'd02-profile-missing');
        $contexts->run(ConsumerExecutionContext::member($missing, 'test.member.profile.missing'), static fn() => $profile->updateSelfField($missing, 999, 'nickname', 'missing'));
        throw new RuntimeException('missing member profile update was accepted');
    } catch (BusinessException $exception) {
        expectMemberProfile($exception->errorCode === 'MEMBER_NOT_FOUND', 'missing-member rejection changed');
    }

    try {
        $contexts->run(ConsumerExecutionContext::member($alpha, 'test.member.profile.cross-tenant'), static fn() => $profile->updateSelfField($alpha, 502, 'nickname', 'forbidden'));
        throw new RuntimeException('cross-tenant profile update was accepted');
    } catch (BusinessException $exception) {
        expectMemberProfile($exception->errorCode === 'MEMBER_PROFILE_SELF_FORBIDDEN', 'cross-tenant rejection changed');
    }
    expectMemberProfile(
        (string) $contexts->run(ConsumerExecutionContext::member($beta, 'test.member.profile.cross-tenant-read'), static fn() => Member::where([])->where('id', 502)->value('nickname')) === 'Beta',
        'cross-tenant profile update mutated the target',
    );
    try {
        $contexts->run(ConsumerExecutionContext::member($alpha, 'test.member.profile.other-member'), static fn() => $profile->updateSelfField($alpha, 503, 'nickname', 'forbidden'));
        throw new RuntimeException('same-tenant other-member profile update was accepted');
    } catch (BusinessException $exception) {
        expectMemberProfile($exception->errorCode === 'MEMBER_PROFILE_SELF_FORBIDDEN', 'same-tenant rejection changed');
    }
    expectMemberProfile(
        (string) $contexts->run(ConsumerExecutionContext::member($alpha, 'test.member.profile.other-member-read'), static fn() => Member::where([])->where('id', 503)->value('nickname')) === 'Alpha peer',
        'same-tenant other-member profile update mutated the target',
    );
} finally {
    IsolatedBackendEnvironment::cleanup();
    RegisteredMysqlTestResource::cleanup($pdo, $database, $createdDatabase);
}

echo "D02-MEMBER-PROFILE-FIELD-VALIDATION-001 passed ({$memberProfileAssertions} assertions)\n";
