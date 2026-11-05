<?php

declare(strict_types=1);

use PeanutAdmin\Modules\OAuth\Contract\OAuthQueries;
use PeanutAdmin\Modules\Member\Service\MemberIdentityContractService;
use PeanutAdmin\Modules\Member\Contract\Dto\MemberIdentitySnapshot;
use PeanutAdmin\Modules\OAuth\Service\OAuthCommandService;
use PeanutAdmin\Modules\OAuth\Infrastructure\Persistence\ThinkPhpOAuthPersistence as OAuthPersistence;
use app\common\execution\ExecutionContextStore;
use PeanutAdmin\Modules\Integration\Contract\ExternalTenantBinding;
use PeanutAdmin\Modules\Integration\Contract\ExternalTenantContext;
use PeanutAdmin\Modules\Integration\Service\ExternalTenantResolver;
use PeanutAdmin\Modules\OAuth\Contract\OAuthCallbackLocator;
use PeanutAdmin\IntegrationSecurity\OAuth\OAuthProfile;
use PeanutAdmin\IntegrationSecurity\OAuth\OAuthTransport;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Auth\ValidatedTenantSession;
use PeanutAdmin\Kernel\Context\TenantSystemContext;

require_once __DIR__ . '/../Support/CiBootstrap.php';
require __DIR__ . '/../Support/IsolatedBackendEnvironment.php';
require_once __DIR__ . '/../Support/IdentityMysqlFixture.php';
require_once __DIR__ . '/../Support/ThinkPhpTestConnection.php';
require_once __DIR__ . '/../Support/RegisteredMysqlTestResource.php';
spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'app\\')) {
        return;
    }
    $path = dirname(__DIR__, 2) . '/app/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    if (is_file($path)) {
        require_once $path;
    }
}, true, true);

function expectOAuthTenant(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function oauthTenantContext(int $tenantId, int $memberId, string $requestId): TenantContext
{
    return TenantContext::fromValidatedSession(new ValidatedTenantSession(
        $memberId,
        'oauth-session-' . $tenantId . '-' . $memberId,
        $tenantId,
        $memberId + 10000,
        $memberId,
        'member-client',
        new DateTimeImmutable('2031-01-01T00:00:00Z'),
        1,
        1,
    ), $requestId);
}

function oauthPdo(string $host, int $port, string $user, string $password, string $database): PDO
{
    return new PDO(
        "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4",
        $user,
        $password,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::MYSQL_ATTR_MULTI_STATEMENTS => true],
    );
}

function oauthFreshSchema(PDO $pdo, string $serverRoot): void
{
    IdentityMysqlFixture::install($pdo, [
        $serverRoot . '/app/modules/peanut/settings/database/migrations',
        $serverRoot . '/app/modules/peanut/integration/database/migrations',
        $serverRoot . '/app/modules/peanut/notification/database/migrations',
        $serverRoot . '/app/modules/peanut/member/database/migrations',
        $serverRoot . '/app/modules/peanut/oauth/database/migrations',
    ]);
    $pdo->exec(<<<'SQL'
INSERT INTO pa_tenant
  (id, code, name, display_name, status, activated_at, created_at, updated_at)
VALUES (101, 'default', 'Alpha', 'Alpha', 'active', UTC_TIMESTAMP(3), UTC_TIMESTAMP(3), UTC_TIMESTAMP(3));
SQL);
    $pdo->exec("INSERT INTO pa_tenant_setting (tenant_id, namespace, config_json, revision, create_time, update_time) VALUES (101, 'login', JSON_OBJECT('login_way', JSON_ARRAY(1, 2), 'coerce_mobile', 0), 1, 0, 0)");
    $pdo->exec("INSERT INTO pa_external_channel_binding (tenant_id, provider, callback_key, identity_hash, identity_hint, config_json, status) VALUES (101, 'oauth.wechat.oa', SHA2('alpha-oauth-callback', 256), SHA2('alpha-oauth-identity', 256), 'alpha', JSON_OBJECT(), 1)");
}

final readonly class OAuthTenantFixtureTransport implements OAuthTransport
{
    public function __construct(
        private string $subject,
        private string $unionId,
        private string $nickname,
    ) {}

    public function authorizationUrl(string $scene, array $config, string $redirectUri, string $state): string
    {
        return 'https://fixture.invalid/oauth';
    }

    public function exchange(string $scene, array $config, string $code): OAuthProfile
    {
        return new OAuthProfile($this->subject, $this->unionId, $this->nickname, '');
    }
}

function oauthSystemContext(int $tenantId, string $operationId): TenantSystemContext
{
    return new TenantSystemContext(
        $tenantId,
        ExternalTenantResolver::ACTOR,
        'oauth.mini-program',
        $operationId,
    );
}

function oauthBinding(int $id, int $tenantId): ExternalTenantBinding
{
    return new ExternalTenantBinding(
        $id,
        $tenantId,
        ExternalTenantResolver::WECHAT_MINI_PROGRAM,
        'fixture-callback',
        'fixture-identity',
        'fixture',
        ['app_id' => 'concurrent-app'],
        true,
        true,
    );
}

function oauthRunSystem(TenantSystemContext $context, callable $operation): mixed
{
    return app(ExecutionContextStore::class)->run(new \app\common\execution\SystemExecutionContext($context), $operation);
}

function oauthRunTenant(TenantContext $context, string $operationId, callable $operation): mixed
{
    return app(ExecutionContextStore::class)->run(
        new \app\common\execution\AdminExecutionContext($context, 'test.oauth.' . $operationId),
        $operation,
    );
}

function oauthCommands(OAuthTransport $transport): OAuthCommandService
{
    return new OAuthCommandService(
        app(\PeanutAdmin\Modules\Member\Contract\MemberQueries::class),
        app(\PeanutAdmin\Modules\Member\Contract\MemberIdentityCommands::class),
        app(\PeanutAdmin\Modules\Member\Contract\MemberProfileCommands::class),
        app(\PeanutAdmin\Modules\Notification\Contract\VerificationCodeCommands::class),
        app(\app\common\persistence\AdvisoryLockExecution::class),
        app(\PeanutAdmin\Modules\Settings\Service\TenantApplicationSettingService::class),
        app(ExternalTenantResolver::class),
        app(OAuthPersistence::class),
        $transport,
        '',
    );
}

if (($argv[1] ?? '') === 'oauth-worker') {
    $database = RegisteredMysqlTestResource::configuredDatabaseName();
    $pdo = oauthPdo(IsolatedBackendEnvironment::required('DB_HOST'), (int) IsolatedBackendEnvironment::required('DB_PORT'), IsolatedBackendEnvironment::required('DB_USER'), IsolatedBackendEnvironment::required('DB_PASS'), $database);
    RegisteredMysqlTestResource::assertSelectedDatabase($pdo, $database);
    $app = new think\App();
    $app->initialize();
    ThinkPhpTestConnection::fromPdo($pdo);
    $tenantId = (int) ($argv[2] ?? 0);
    $bindingId = (int) ($argv[3] ?? 0);
    $subject = (string) ($argv[4] ?? '');
    $unionId = (string) ($argv[5] ?? '');
    $context = oauthSystemContext($tenantId, 'oauth-worker-' . getmypid());
    $result = oauthRunSystem(
        $context,
        static fn() => oauthCommands(new OAuthTenantFixtureTransport($subject, $unionId, 'Concurrent member'))->miniProgramLogin(
            $context,
            'fixture-code',
            oauthBinding($bindingId, $tenantId),
            '127.0.0.1',
        ),
    );
    if ($result === false) {
        throw new RuntimeException('concurrent OAuth worker failed');
    }
    echo json_encode($result, JSON_THROW_ON_ERROR) . PHP_EOL;
    exit(0);
}

$serverRoot = dirname(__DIR__, 2);
$host = IsolatedBackendEnvironment::required('DB_HOST');
$port = (int) IsolatedBackendEnvironment::required('DB_PORT');
$database = IsolatedBackendEnvironment::required('DB_NAME');
$user = IsolatedBackendEnvironment::required('DB_USER');
$password = IsolatedBackendEnvironment::required('DB_PASS');
$database = RegisteredMysqlTestResource::configuredDatabaseName();
[$pdo, $createdDatabase] = RegisteredMysqlTestResource::openEmptyDatabase($database);

try {
    IsolatedBackendEnvironment::activateDatabase($host, $port, $database, $user, $password, 'multi-tenant');
    $fixtureApp = new think\App($serverRoot);
    $fixtureApp->initialize();
    ThinkPhpTestConnection::fromPdo($pdo);
    oauthFreshSchema($pdo, $serverRoot);
    $pdo->exec(<<<'SQL'
INSERT INTO pa_tenant
  (id, code, name, display_name, status, activated_at, created_at, updated_at)
VALUES (202, 'beta', 'Beta', 'Beta', 'active', UTC_TIMESTAMP(3), UTC_TIMESTAMP(3), UTC_TIMESTAMP(3));
INSERT INTO pa_member (id, tenant_id, sn, account, nickname, status)
VALUES
  (11, 101, 'M-ALPHA', 'alpha', 'Alpha', 1),
  (22, 202, 'M-BETA', 'beta', 'Beta', 1);
INSERT INTO pa_external_channel_binding
  (id, tenant_id, provider, callback_key, identity_hash, identity_hint, config_json, status)
VALUES
  (202, 202, 'oauth.wechat.oa', SHA2('beta-oauth-callback', 256), SHA2('beta-oauth-identity', 256), 'beta', JSON_OBJECT(), 1),
  (301, 101, 'oauth.wechat.mnp', SHA2('alpha-mnp-callback', 256), SHA2('alpha-mnp-identity', 256), 'alpha-mnp', JSON_OBJECT('app_id', 'concurrent-app'), 1),
  (302, 202, 'oauth.wechat.mnp', SHA2('beta-mnp-callback', 256), SHA2('beta-mnp-identity', 256), 'beta-mnp', JSON_OBJECT('app_id', 'concurrent-app'), 1);
INSERT INTO pa_oauth_principal (id, tenant_id, provider, union_scope, union_id, member_id)
VALUES (31, 101, 'wechat', 'wechat_default', 'union-shared', 11);
INSERT INTO pa_oauth_identity (id, tenant_id, provider, client_key, subject, principal_id, member_id, terminal)
VALUES (41, 101, 'wechat', 'mnp:app-instance', 'openid-shared', 31, 11, 1);
INSERT INTO pa_oauth_attempt (id, tenant_id, state_hash, scene, return_path, expires_at)
VALUES (51, 101, REPEAT('a', 64), 'oa', '/alpha', 2147483647);
INSERT INTO pa_oauth_completion_ticket
  (id, tenant_id, binding_id, token_hash, member_id, need_profile, need_mobile, expires_at)
SELECT 61, 101, id, REPEAT('b', 64), 11, 1, 0, 2147483647
FROM pa_external_channel_binding
WHERE tenant_id = 101 AND provider = 'oauth.wechat.oa';
UPDATE pa_tenant_setting
SET config_json = JSON_SET(config_json, '$.coerce_mobile', 1)
WHERE tenant_id = 101 AND namespace = 'login';
INSERT INTO pa_tenant_setting (tenant_id, namespace, config_json, revision, create_time, update_time)
VALUES (202, 'login', JSON_OBJECT('login_way', JSON_ARRAY(1, 2), 'coerce_mobile', 1), 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP());
SQL);
    IsolatedBackendEnvironment::activateDatabase($host, $port, $database, $user, $password, 'multi-tenant');
    $app = new think\App();
    $app->initialize();
    ThinkPhpTestConnection::fromPdo($pdo);
    $persistence = app(OAuthPersistence::class);
    $locator = app(OAuthCallbackLocator::class);

    $alpha = oauthTenantContext(101, 11, 'fresh-oauth-alpha');
    $beta = oauthTenantContext(202, 22, 'fresh-oauth-beta');

    $identityCommands = app(MemberIdentityContractService::class);
    $loginContext = oauthSystemContext(101, 'normal-login');
    $loggedIn = oauthRunSystem($loginContext, static function () use ($identityCommands, $loginContext): MemberIdentitySnapshot {
        $identityCommands->register($loginContext, 'normal-login', 'secret-pass', '');
        return $identityCommands->login($loginContext, 'normal-login', 'secret-pass', '127.0.0.1');
    });
    expectOAuthTenant($loggedIn instanceof MemberIdentitySnapshot, 'normal login leaked a writable Member model');
    expectOAuthTenant($loggedIn->id > 0 && $loggedIn->status === 1, 'normal login identity snapshot changed');
    expectOAuthTenant((new ReflectionClass($loggedIn))->isReadOnly(), 'member identity snapshot is writable');

    $membersBeforeConcurrent = (int) $pdo->query('SELECT COUNT(*) FROM pa_member WHERE tenant_id = 101')->fetchColumn();
    $commands = [
        [PHP_BINARY, __FILE__, 'oauth-worker', '101', '301', 'same-concurrent-subject', 'same-concurrent-union'],
        [PHP_BINARY, __FILE__, 'oauth-worker', '101', '301', 'same-concurrent-subject', 'same-concurrent-union'],
    ];
    $workers = [];
    foreach ($commands as $command) {
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        expectOAuthTenant(is_resource($process), 'concurrent OAuth worker did not start');
        $workers[] = [$process, $pipes];
    }
    foreach ($workers as [$process, $pipes]) {
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        expectOAuthTenant(proc_close($process) === 0, 'concurrent OAuth worker failed: ' . trim((string) $stderr));
        expectOAuthTenant(json_validate(trim((string) $stdout)), 'concurrent OAuth worker returned invalid output');
    }
    expectOAuthTenant(
        (int) $pdo->query('SELECT COUNT(*) FROM pa_member WHERE tenant_id = 101')->fetchColumn() === $membersBeforeConcurrent + 1,
        'same OAuth identity concurrency created duplicate members',
    );
    expectOAuthTenant(
        (int) $pdo->query("SELECT COUNT(*) FROM pa_oauth_identity WHERE tenant_id = 101 AND subject = 'same-concurrent-subject'")->fetchColumn() === 1,
        'same OAuth identity concurrency created duplicate identities',
    );

    $betaLoginContext = oauthSystemContext(202, 'beta-same-identity');
    $betaOAuth = oauthRunSystem(
        $betaLoginContext,
        static fn() => oauthCommands(new OAuthTenantFixtureTransport('same-concurrent-subject', 'same-concurrent-union', 'Beta member'))->miniProgramLogin(
            $betaLoginContext,
            'fixture-code',
            oauthBinding(302, 202),
            '127.0.0.1',
        ),
    );
    expectOAuthTenant($betaOAuth !== false, 'Beta OAuth login failed');
    expectOAuthTenant(
        (int) $pdo->query("SELECT COUNT(*) FROM pa_oauth_identity WHERE tenant_id = 202 AND subject = 'same-concurrent-subject'")->fetchColumn() === 1,
        'same OAuth identity did not remain isolated in Beta',
    );

    $betaMembersBeforeRollback = (int) $pdo->query('SELECT COUNT(*) FROM pa_member WHERE tenant_id = 202')->fetchColumn();
    $betaPrincipalsBeforeRollback = (int) $pdo->query('SELECT COUNT(*) FROM pa_oauth_principal WHERE tenant_id = 202')->fetchColumn();
    $pdo->exec("ALTER TABLE pa_oauth_identity ADD CONSTRAINT chk_oauth_rollback_subject CHECK (subject <> 'rollback-subject')");
    $rollbackContext = oauthSystemContext(202, 'beta-rollback');
    $rolledBack = oauthRunSystem(
        $rollbackContext,
        static fn() => oauthCommands(new OAuthTenantFixtureTransport('rollback-subject', 'rollback-union', 'Rollback member'))->miniProgramLogin(
            $rollbackContext,
            'fixture-code',
            oauthBinding(302, 202),
            '127.0.0.1',
        ),
    );
    expectOAuthTenant($rolledBack === false, 'forced OAuth identity failure unexpectedly succeeded');
    expectOAuthTenant(
        (int) $pdo->query('SELECT COUNT(*) FROM pa_member WHERE tenant_id = 202')->fetchColumn() === $betaMembersBeforeRollback,
        'failed OAuth identity creation did not roll back the Member write',
    );
    expectOAuthTenant(
        (int) $pdo->query('SELECT COUNT(*) FROM pa_oauth_principal WHERE tenant_id = 202')->fetchColumn() === $betaPrincipalsBeforeRollback,
        'failed OAuth identity creation did not roll back the principal write',
    );
    $betaPrincipal = oauthRunTenant($beta, 'create-beta-principal', static fn() => $persistence->createPrincipal($beta, [
        'tenant_id' => 101,
        'provider' => 'wechat', 'union_scope' => 'wechat_default',
        'union_id' => 'union-shared', 'member_id' => 22,
    ]));
    expectOAuthTenant($betaPrincipal->memberId === 22, 'payload forged OAuth principal Tenant ownership');
    oauthRunTenant($beta, 'create-beta-identity', static fn() => $persistence->createIdentity($beta, [
        'tenant_id' => 101,
        'provider' => 'wechat', 'client_key' => 'mnp:app-instance',
        'subject' => 'openid-shared', 'principal_id' => (int) $betaPrincipal->id,
        'member_id' => 22, 'terminal' => 1,
    ]));
    $betaIdentity = oauthRunTenant($beta, 'read-beta-identity', static fn() => $persistence->identityBySubjectForUpdate(
        $beta,
        'wechat',
        'mnp:app-instance',
        'openid-shared',
    ));
    expectOAuthTenant($betaIdentity?->memberId === 22, 'payload forged OAuth identity Tenant ownership');
    expectOAuthTenant(oauthRunTenant($alpha, 'read-beta-identity', static fn() => $persistence->identityBySubjectForUpdate(
        $alpha,
        'wechat',
        'mnp:app-instance',
        'openid-shared',
    ))?->memberId === 11, 'Alpha read Beta OAuth identity');
    expectOAuthTenant(oauthRunTenant($beta, 'read-beta-subject', static fn() => app(OAuthQueries::class)
        ->wechatSubjectForMember($beta, 22, 1)) === 'openid-shared', 'payment compatibility lookup lost Beta owned subject');

    $sameStateHash = str_repeat('c', 64);
    $alphaBegin = new TenantSystemContext(101, ExternalTenantResolver::ACTOR, 'oauth.begin', 'alpha-begin');
    oauthRunSystem($alphaBegin, static fn() => $persistence->createAttempt($alphaBegin, [
        'state_hash' => $sameStateHash, 'scene' => 'oa', 'return_path' => '/alpha', 'expires_at' => time() + 600,
    ]));
    $betaBegin = new TenantSystemContext(202, ExternalTenantResolver::ACTOR, 'oauth.begin', 'beta-begin');
    oauthRunSystem($betaBegin, static fn() => $persistence->createAttempt($betaBegin, [
        'tenant_id' => 101, 'state_hash' => $sameStateHash, 'scene' => 'oa', 'return_path' => '/beta', 'expires_at' => time() + 600,
    ]));
    expectOAuthTenant(oauthRunTenant($alpha, 'count-alpha-state', static fn() => $persistence->attemptForUpdate($alpha, $sameStateHash))?->returnPath === '/alpha', 'Alpha OAuth state was not isolated');
    expectOAuthTenant(oauthRunTenant($beta, 'count-beta-state', static fn() => $persistence->attemptForUpdate($beta, $sameStateHash))?->returnPath === '/beta', 'Beta OAuth state was not isolated');

    oauthRunTenant($beta, 'create-beta-ticket', static fn() => $persistence->createCompletion($beta, [
        'tenant_id' => 101, 'token_hash' => str_repeat('d', 64), 'member_id' => 22,
        'binding_id' => 202, 'need_profile' => 1, 'need_mobile' => 0, 'expires_at' => time() + 600,
    ]));
    expectOAuthTenant(oauthRunTenant($alpha, 'read-beta-ticket', static fn() => $persistence->completionForUpdate($alpha, str_repeat('d', 64))) === null, 'Alpha read Beta completion ticket');

    $officialProvider = ExternalTenantResolver::WECHAT_OFFICIAL_OAUTH;
    $openPlatformProvider = ExternalTenantResolver::WECHAT_OPEN_PLATFORM;
    $validStateHash = str_repeat('a', 64);
    expectOAuthTenant(count($locator->locateState($officialProvider, $validStateHash)) === 1, 'valid OAuth state was not located');
    expectOAuthTenant(count($locator->locateState($openPlatformProvider, $validStateHash)) === 0, 'wrong OAuth binding provider accepted state');
    $expiredStateHash = str_repeat('e', 64);
    $expiredStateContext = new TenantSystemContext(101, ExternalTenantResolver::ACTOR, 'oauth.begin', 'expired-state');
    oauthRunSystem($expiredStateContext, static fn() => $persistence->createAttempt($expiredStateContext, [
        'state_hash' => $expiredStateHash, 'scene' => 'oa', 'return_path' => '/expired', 'expires_at' => time() - 1,
    ]));
    expectOAuthTenant(count($locator->locateState($officialProvider, $expiredStateHash)) === 0, 'expired OAuth state was accepted');
    $alphaAttempt = oauthRunTenant($alpha, 'find-alpha-state', static fn() => $persistence->attemptForUpdate($alpha, $validStateHash));
    oauthRunTenant($alpha, 'consume-alpha-state', static fn() => $persistence->markAttemptUsed($alpha, $alphaAttempt->id, time()));
    expectOAuthTenant(count($locator->locateState($officialProvider, $validStateHash)) === 0, 'replayed OAuth state was accepted');

    $alphaBindingId = (int) $pdo->query("SELECT id FROM pa_external_channel_binding WHERE tenant_id = 101 AND provider = 'oauth.wechat.oa'")->fetchColumn();
    expectOAuthTenant($alphaBindingId > 0, 'Alpha OAuth binding is missing');
    $ticketHash = str_repeat('f', 64);
    $expiredTicketHash = str_repeat('9', 64);
    oauthRunTenant($alpha, 'create-alpha-ticket', static fn() => $persistence->createCompletion($alpha, [
        'token_hash' => $ticketHash, 'binding_id' => $alphaBindingId, 'member_id' => 11,
        'need_profile' => 0, 'need_mobile' => 0, 'expires_at' => time() + 600,
    ]));
    oauthRunTenant($alpha, 'create-expired-alpha-ticket', static fn() => $persistence->createCompletion($alpha, [
        'token_hash' => $expiredTicketHash, 'binding_id' => $alphaBindingId, 'member_id' => 11,
        'need_profile' => 0, 'need_mobile' => 0, 'expires_at' => time() - 1,
    ]));
    expectOAuthTenant(count($locator->locateTicket($ticketHash)) === 1, 'valid OAuth ticket was not located');
    expectOAuthTenant(count($locator->locateTicket($expiredTicketHash)) === 0, 'expired OAuth ticket was accepted');
    $alphaTicket = oauthRunTenant($alpha, 'find-alpha-ticket', static fn() => $persistence->completionForUpdate($alpha, $ticketHash));
    oauthRunTenant($alpha, 'consume-alpha-ticket', static fn() => $persistence->markCompletionUsed($alpha, $alphaTicket->id, time()));
    expectOAuthTenant(count($locator->locateTicket($ticketHash)) === 0, 'replayed OAuth ticket was accepted');

    try {
        ExternalTenantContext::tenantId(new TenantSystemContext(202, 'forged.actor', 'oauth.begin', 'forged'));
        throw new RuntimeException('forged OAuth system actor was accepted');
    } catch (Throwable $exception) {
        expectOAuthTenant($exception->getMessage() !== '', 'forged OAuth actor denial lost shape');
    }

    $controller = (string) file_get_contents($serverRoot . '/app/modules/peanut/oauth/src/Controller/MemberOAuthController.php');
    $application = (string) file_get_contents($serverRoot . '/app/modules/peanut/oauth/src/Service/OAuthEntryService.php');
    expectOAuthTenant(
        str_contains($controller, '$this->application->begin(')
        && str_contains($application, '$this->externalTenants->onlyActiveBinding(')
        && str_contains($application, "assertExternalCallback('peanut.oauth')"),
        'OAuth begin does not use the trusted external binding and module boundary',
    );
    foreach ([
        'app/api/application/LoginApplicationService.php',
        'app/modules/peanut/oauth/src/Service/OAuthCommandService.php',
        'app/api/middleware/CheckTokenMiddleware.php',
        'app/modules/peanut/oauth/src/Infrastructure/Persistence/ThinkPhpOAuthPersistence.php',
    ] as $relative) {
        $source = (string) file_get_contents($serverRoot . '/' . $relative);
        expectOAuthTenant(!str_contains($source, 'Member\\Model\\Member'), 'Member model leaked outside its owner: ' . $relative);
        expectOAuthTenant(!str_contains($source, 'MemberTenantRepository'), 'Member repository leaked outside its owner: ' . $relative);
    }
} finally {
    RegisteredMysqlTestResource::cleanup($pdo, $database, $createdDatabase);
}

echo "OAuth tenant isolation passed\n";
