<?php

declare(strict_types=1);

use PeanutAdmin\Modules\Notification\Service\NotificationApplicationService;
use PeanutAdmin\Modules\Notification\Validation\NoticeSceneValidate;
use PeanutAdmin\Modules\Notification\Model\NoticeLog;
use PeanutAdmin\Modules\Notification\Model\NoticeScene;
use app\common\execution\CurrentExecutionContext;
use app\common\execution\ExecutionContextStore;
use app\common\context\notice\NoticeTenantContext;
use PeanutAdmin\Modules\Notification\Delivery\Application\VerificationCodeSecret;
use PeanutAdmin\Modules\Notification\Contract\NoticeSmsSender;
use PeanutAdmin\Modules\Notification\Service\VerificationCodeService;
use PeanutAdmin\Modules\Member\Service\VerificationAttemptRateLimiter;
use PeanutAdmin\Modules\Member\Service\LoginApplicationService;
use app\common\exception\BusinessException;
use app\common\value\notice\sms\SmsDriverResult;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Auth\ValidatedTenantSession;
use PeanutAdmin\Kernel\Context\TenantSystemContext;

require_once __DIR__ . '/../Support/CiBootstrap.php';
require __DIR__ . '/../Support/IsolatedBackendEnvironment.php';
require_once __DIR__ . '/../Support/IdentityMysqlFixture.php';
require_once __DIR__ . '/../Support/ThinkPhpTestConnection.php';
require_once __DIR__ . '/../Support/RegisteredMysqlTestResource.php';

$noticeAssertions = 0;

function expectNoticeTenant(bool $condition, string $message): void
{
    global $noticeAssertions;
    $noticeAssertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function noticeTenantContext(int $tenantId, int $accountId, int $memberId, string $requestId): TenantContext
{
    return TenantContext::fromValidatedSession(new ValidatedTenantSession(
        $memberId,
        'notice-session-' . $tenantId . '-' . $memberId,
        $tenantId,
        $accountId,
        $memberId,
        'admin-web',
        new DateTimeImmutable('2031-01-01T00:00:00Z'),
        1,
        1,
    ), $requestId);
}

function runNoticeTenant(TenantContext $context, string $operation, callable $callback): mixed
{
    return app(ExecutionContextStore::class)->run(
        new \app\common\execution\AdminExecutionContext($context, $operation),
        $callback,
    );
}

function noticeFreshSchema(PDO $pdo, string $serverRoot): void
{
    IdentityMysqlFixture::install($pdo, [
        $serverRoot . '/app/modules/peanut/settings/database/migrations',
        $serverRoot . '/app/modules/peanut/integration/database/migrations',
        $serverRoot . '/app/modules/peanut/notification/database/migrations',
        $serverRoot . '/app/modules/peanut/member/database/migrations',
    ]);
    $pdo->exec(<<<'SQL'
INSERT INTO pa_tenant
  (id, code, name, display_name, status, activated_at, created_at, updated_at)
VALUES (101, 'default', 'Alpha', 'Alpha', 'active', UTC_TIMESTAMP(3), UTC_TIMESTAMP(3), UTC_TIMESTAMP(3));
SQL);
    $context = new \app\common\execution\SystemExecutionContext(new TenantSystemContext(101, 'module.notification', 'notification.provision-tenant-defaults', 'notice-fixture-defaults'));
    app(ExecutionContextStore::class)->run($context, fn() => app(\PeanutAdmin\Modules\Notification\Service\NotificationBootstrapService::class)->provisionTenantDefaults($context));
    expectNoticeTenant((int) $pdo->query('SELECT COUNT(*) FROM pa_notice_scene WHERE tenant_id = 101')->fetchColumn() === 4, 'notification bootstrap did not provision four tenant-owned verification scenes');
    expectNoticeTenant((int) $pdo->query('SELECT COUNT(*) FROM pa_notice_scene WHERE tenant_id <> 101')->fetchColumn() === 0, 'notification bootstrap provisioned another tenant');
}

/** @return list<array{accepted:bool,error:string}> */
function noticeConcurrentVerifications(string $mobile, string $code): array
{
    $barrier = tempnam(sys_get_temp_dir(), 'd01-verification-');
    if ($barrier === false) {
        throw new RuntimeException('verification concurrency barrier create failed');
    }
    unlink($barrier);
    $worker = __DIR__ . '/VerificationCodeConcurrentWorker.php';
    $environment = getenv();
    if (!is_array($environment)) {
        throw new RuntimeException('verification concurrency environment is unavailable');
    }
    $environment['PEANUT_SERVER_ENV_FILE'] = IsolatedBackendEnvironment::required('PEANUT_SERVER_ENV_FILE');
    $processes = [];
    try {
        for ($index = 1; $index <= 2; $index++) {
            $pipes = [];
            $process = proc_open(
                [PHP_BINARY, $worker, $mobile, $code, 'concurrent-' . $index, $barrier],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
                null,
                $environment,
            );
            if (!is_resource($process)) {
                throw new RuntimeException('verification concurrency worker start failed');
            }
            $processes[] = [$process, $pipes];
        }
        touch($barrier);
        $results = [];
        foreach ($processes as [$process, $pipes]) {
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            if (proc_close($process) !== 0) {
                throw new RuntimeException('verification concurrency worker failed: ' . trim($stderr));
            }
            $result = json_decode($stdout, true);
            if (!is_array($result) || !isset($result['accepted'], $result['error'])) {
                throw new RuntimeException('verification concurrency worker result invalid');
            }
            $results[] = ['accepted' => (bool) $result['accepted'], 'error' => (string) $result['error']];
        }
        return $results;
    } finally {
        foreach ($processes as [$process, $pipes]) {
            foreach ($pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            if (is_resource($process)) {
                proc_terminate($process);
            }
        }
        if (is_file($barrier)) {
            unlink($barrier);
        }
    }
}

final class SuccessfulNoticeSender implements NoticeSmsSender
{
    public int $calls = 0;
    public ?Closure $barrier = null;

    public function send(
        TenantContext|TenantSystemContext $context,
        string $mobile,
        string $templateId,
        array $variables,
        ?callable $beforeSend = null,
    ): array {
        $this->calls++;
        if ($beforeSend !== null) {
            $beforeSend('fixture-provider');
        }
        if ($this->barrier !== null) {
            $barrier = $this->barrier;
            $this->barrier = null;
            $barrier();
        }
        return [
            'success' => true,
            'outcome' => SmsDriverResult::OUTCOME_SUCCEEDED,
            'provider' => 'fixture-provider',
            'error' => '',
            'result' => [],
        ];
    }
}

final class OutcomeNoticeSender implements NoticeSmsSender
{
    public int $calls = 0;

    public function __construct(private readonly string $outcome) {}

    public function send(
        TenantContext|TenantSystemContext $context,
        string $mobile,
        string $templateId,
        array $variables,
        ?callable $beforeSend = null,
    ): array {
        $this->calls++;
        if ($beforeSend !== null) {
            $beforeSend('fixture-provider');
        }
        return [
            'success' => $this->outcome === SmsDriverResult::OUTCOME_SUCCEEDED,
            'outcome' => $this->outcome,
            'provider' => 'fixture-provider',
            'error' => $this->outcome === SmsDriverResult::OUTCOME_FAILED ? 'fixture rejected' : '',
            'result' => [],
        ];
    }
}

$serverRoot = dirname(__DIR__, 2);
$host = IsolatedBackendEnvironment::required('DB_HOST');
$port = (int) IsolatedBackendEnvironment::required('DB_PORT');
$user = IsolatedBackendEnvironment::required('DB_USER');
$password = IsolatedBackendEnvironment::required('DB_PASS');
$database = RegisteredMysqlTestResource::configuredDatabaseName();
[$pdo, $createdDatabase] = RegisteredMysqlTestResource::openEmptyDatabase($database);

try {
    IsolatedBackendEnvironment::activateDatabase($host, $port, $database, $user, $password, 'multi-tenant');
    $fixtureApp = new think\App($serverRoot);
    $fixtureApp->initialize();
    ThinkPhpTestConnection::fromPdo($pdo);
    noticeFreshSchema($pdo, $serverRoot);
    $pdo->exec(<<<'SQL'
INSERT INTO pa_notice_template
  (id, tenant_id, name, code, channel, content, create_time, update_time)
VALUES (21, 101, 'Alpha template', 'member_notice', 1, 'Alpha ${code}', UNIX_TIMESTAMP(), UNIX_TIMESTAMP());
INSERT INTO pa_notice_log
  (id, tenant_id, template_id, scene_id, channel, provider, receiver, title, content, status, error, extra, send_time, create_time)
VALUES (31, 101, 21, 1, 1, 'fixture-provider', '13900000000', 'Alpha', 'redacted', 2, '', '{}', UNIX_TIMESTAMP() - 600, UNIX_TIMESTAMP() - 600);
INSERT INTO pa_tenant
  (id, code, name, display_name, status, activated_at, created_at, updated_at)
VALUES (202, 'beta', 'Beta', 'Beta', 'active', UTC_TIMESTAMP(3), UTC_TIMESTAMP(3), UTC_TIMESTAMP(3));
INSERT INTO pa_notice_scene
  (id, tenant_id, code, name, variables, sms_template_id, sms_content, sms_status, create_time, update_time)
VALUES (112, 202, 'login_code', 'Beta login', JSON_ARRAY('code'), 'beta-login', 'Beta ${code}', 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP());
INSERT INTO pa_notice_template
  (id, tenant_id, name, code, channel, content, create_time, update_time)
VALUES (121, 202, 'Beta template', 'member_notice', 1, 'Beta ${code}', UNIX_TIMESTAMP(), UNIX_TIMESTAMP());
SQL);
    expectNoticeTenant(
        (int) $pdo->query("SELECT COUNT(*) FROM pa_notice_scene WHERE code = 'login_code'")->fetchColumn() === 2,
        'tenant-scoped scene code cannot be reused across Tenants',
    );
    expectNoticeTenant(
        (int) $pdo->query("SELECT COUNT(*) FROM pa_notice_template WHERE code = 'member_notice'")->fetchColumn() === 2,
        'tenant-scoped template key cannot be reused across Tenants',
    );
    try {
        $pdo->exec(<<<'SQL'
INSERT INTO pa_notice_template
  (tenant_id, name, code, channel, content, create_time, update_time)
VALUES (202, 'Duplicate Beta template', 'member_notice', 1, 'Beta duplicate', UNIX_TIMESTAMP(), UNIX_TIMESTAMP())
SQL);
        throw new RuntimeException('duplicate template key unexpectedly succeeded inside one Tenant');
    } catch (PDOException $exception) {
        expectNoticeTenant($exception->getCode() === '23000', 'tenant-scoped template uniqueness failed with an unexpected shape');
    }

    IsolatedBackendEnvironment::activateDatabase($host, $port, $database, $user, $password, 'multi-tenant');
    $app = new think\App();
    $app->initialize();
    ThinkPhpTestConnection::fromPdo($pdo);

    $alpha = noticeTenantContext(101, 1001, 501, 'fresh-notice-alpha');
    $beta = noticeTenantContext(202, 2002, 502, 'fresh-notice-beta');
    $notifications = app(NotificationApplicationService::class);
    $contexts = app(CurrentExecutionContext::class);
    $invalidSend = new TenantSystemContext(0, NoticeTenantContext::VERIFICATION_ACTOR, 'notice.verification.send', 'invalid-send');
    $invalidVerify = new TenantSystemContext(0, NoticeTenantContext::VERIFICATION_ACTOR, 'notice.verification.verify', 'invalid-verify');

    $request = new stdClass();
    try {
        app(CurrentExecutionContext::class)->tenantAdmin();
        throw new RuntimeException('missing TenantContext unexpectedly succeeded');
    } catch (Throwable $exception) {
        expectNoticeTenant($exception->getMessage() !== '', 'missing TenantContext denial lost its shape');
    }

    expectNoticeTenant(runNoticeTenant($alpha, 'test.notice.scenes.alpha', fn() => $notifications->scenes())['total'] === 4, 'Alpha scene list crossed Tenant boundary');
    expectNoticeTenant(runNoticeTenant($beta, 'test.notice.scenes.beta', fn() => $notifications->scenes())['total'] === 1, 'Beta scene list crossed Tenant boundary');
    expectNoticeTenant(runNoticeTenant($alpha, 'test.notice.scene.cross', fn() => $notifications->sceneDetail(112)) === [], 'cross-tenant scene detail was visible');
    expectNoticeTenant(runNoticeTenant($alpha, 'test.notice.scene.missing', fn() => $notifications->sceneDetail(999999)) === [], 'missing scene detail shape changed');
    $betaSceneBefore = $pdo->query("SELECT sms_template_id, sms_content, sms_status FROM pa_notice_scene WHERE id = 112")
        ->fetch(PDO::FETCH_ASSOC);
    $saveErrors = [];
    foreach ([112, 999999] as $sceneId) {
        try {
            runNoticeTenant($alpha, 'test.notice.scene.save.denied', fn() => $notifications->saveScene([
                'id' => $sceneId,
                'sms_template_id' => 'denied',
                'sms_content' => 'Code ${code}',
                'sms_status' => 1,
            ]));
            throw new RuntimeException('invalid scene save unexpectedly succeeded');
        } catch (Throwable $exception) {
            $saveErrors[] = $exception->getMessage();
        }
    }
    expectNoticeTenant(count(array_unique($saveErrors)) === 1, 'scene save enumerated cross-Tenant ownership');
    expectNoticeTenant(
        $pdo->query("SELECT sms_template_id, sms_content, sms_status FROM pa_notice_scene WHERE id = 112")
            ->fetch(PDO::FETCH_ASSOC) === $betaSceneBefore,
        'cross-tenant scene save changed Beta data',
    );

    $validationErrors = [];
    foreach ([112, 999999] as $sceneId) {
        try {
            runNoticeTenant(
                $alpha,
                'test.notice.scene.validate',
                fn() => app(NoticeSceneValidate::class)->scene('detail')->failException(true)->check(['id' => $sceneId]),
            );
            throw new RuntimeException('invalid scene validation unexpectedly succeeded');
        } catch (Throwable $exception) {
            $validationErrors[] = $exception->getMessage();
        }
    }
    expectNoticeTenant(count(array_unique($validationErrors)) === 1, 'scene validator enumerated cross-Tenant ownership');

    $beforeUntrusted = (int) $pdo->query('SELECT COUNT(*) FROM pa_notice_log')->fetchColumn();
    $sender = new SuccessfulNoticeSender();
    $service = new VerificationCodeService($sender, $contexts, false);
    foreach (['send', 'verify'] as $operation) {
        try {
            $operation === 'send'
                ? $service->send($invalidSend, 'login_code', '13800000000')
                : $service->verify($invalidVerify, 'login_code', '13800000000', '4827');
            throw new RuntimeException("untrusted context {$operation} unexpectedly succeeded");
        } catch (Throwable) {
            expectNoticeTenant(
                (int) $pdo->query('SELECT COUNT(*) FROM pa_notice_log')->fetchColumn() === $beforeUntrusted,
                "untrusted context {$operation} wrote a notice log",
            );
        }
    }
    expectNoticeTenant($sender->calls === 0, 'untrusted Tenant context reached the provider Host');

    $mismatchRejected = false;
    try {
        runNoticeTenant(
            $alpha,
            'test.notice.context-mismatch',
            fn() => $service->send($beta, 'login_code', '13800000001'),
        );
    } catch (Throwable) {
        $mismatchRejected = true;
    }
    expectNoticeTenant($mismatchRejected, 'explicit Notification context diverged from the ORM Tenant scope');
    expectNoticeTenant($sender->calls === 0, 'mismatched Notification context reached the provider Host');

    $sendResult = runNoticeTenant(
        $beta,
        'test.notice.verification.send.beta',
        fn() => $service->send($beta, 'login_code', '13800000001'),
    );
    expectNoticeTenant($sendResult->success, $sendResult->error);
    expectNoticeTenant($sender->calls === 1, 'trusted tenant send did not cross the explicit sender port once');
    expectNoticeTenant(
        (int) $pdo->query("SELECT COUNT(*) FROM pa_notice_log WHERE tenant_id = 202 AND receiver = '13800000001'")->fetchColumn() === 1,
        'tenant-owned send log was not written to Beta',
    );
    expectNoticeTenant(
        (int) $pdo->query("SELECT COUNT(*) FROM pa_notice_log WHERE tenant_id = 101 AND receiver = '13800000001'")->fetchColumn() === 0,
        'Beta send log leaked into Alpha',
    );
    $replayedSend = runNoticeTenant(
        $beta,
        'test.notice.verification.send.replay',
        fn() => $service->send($beta, 'login_code', '13800000001'),
    );
    expectNoticeTenant($replayedSend->success, 'same request identity did not replay its successful delivery');
    expectNoticeTenant($sender->calls === 1, 'successful idempotency replay called the Provider again');

    $betaRateLimited = noticeTenantContext(202, 2002, 502, 'fresh-notice-beta-rate-limited');
    $rateLimitedSend = runNoticeTenant(
        $betaRateLimited,
        'test.notice.verification.send.rate-limited',
        fn() => $service->send($betaRateLimited, 'login_code', '13800000001'),
    );
    expectNoticeTenant(!$rateLimitedSend->success, 'active successful reservation allowed another request identity');
    expectNoticeTenant($sender->calls === 1, 'active successful reservation called the Provider again');

    $barrierSender = new SuccessfulNoticeSender();
    $barrierService = new VerificationCodeService($barrierSender, $contexts, false);
    $barrierOwner = noticeTenantContext(202, 2002, 502, 'fresh-notice-beta-barrier-owner');
    $barrierContender = noticeTenantContext(202, 2002, 502, 'fresh-notice-beta-barrier-contender');
    $contendingResult = null;
    $barrierSender->barrier = function () use (
        &$contendingResult,
        $barrierService,
        $barrierContender,
    ): void {
        $contendingResult = runNoticeTenant(
            $barrierContender,
            'test.notice.verification.send.barrier-contender',
            fn() => $barrierService->send($barrierContender, 'login_code', '13800000005'),
        );
    };
    $barrierOwnerResult = runNoticeTenant(
        $barrierOwner,
        'test.notice.verification.send.barrier-owner',
        fn() => $barrierService->send($barrierOwner, 'login_code', '13800000005'),
    );
    expectNoticeTenant($barrierOwnerResult->success, $barrierOwnerResult->error);
    expectNoticeTenant($contendingResult instanceof \PeanutAdmin\Modules\Notification\Contract\DeliveryResult
        && !$contendingResult->success, 'Provider barrier contender was not rejected');
    expectNoticeTenant($barrierSender->calls === 1, 'Provider barrier allowed duplicate delivery');
    expectNoticeTenant(
        (int) $pdo->query("SELECT COUNT(*) FROM pa_notice_log WHERE tenant_id = 202 AND receiver = '13800000005'")->fetchColumn() === 1,
        'Provider barrier produced duplicate reservations',
    );

    $failedSender = new OutcomeNoticeSender(SmsDriverResult::OUTCOME_FAILED);
    $failedService = new VerificationCodeService($failedSender, $contexts, false);
    foreach (['first', 'retry'] as $attempt) {
        $failedContext = noticeTenantContext(202, 2002, 502, 'fresh-notice-beta-failed-' . $attempt);
        $failedResult = runNoticeTenant(
            $failedContext,
            'test.notice.verification.send.failed-' . $attempt,
            fn() => $failedService->send($failedContext, 'login_code', '13800000006'),
        );
        expectNoticeTenant(!$failedResult->success, 'explicit Provider failure was reported as success');
    }
    expectNoticeTenant($failedSender->calls === 2, 'explicit Provider failure did not release the reservation');

    $unknownSender = new OutcomeNoticeSender(SmsDriverResult::OUTCOME_UNKNOWN);
    $unknownService = new VerificationCodeService($unknownSender, $contexts, false);
    $unknownOwner = noticeTenantContext(202, 2002, 502, 'fresh-notice-beta-unknown-owner');
    $unknownResult = runNoticeTenant(
        $unknownOwner,
        'test.notice.verification.send.unknown-owner',
        fn() => $unknownService->send($unknownOwner, 'login_code', '13800000007'),
    );
    expectNoticeTenant(!$unknownResult->success, 'unknown Provider result was reported as success');
    $unknownContender = noticeTenantContext(202, 2002, 502, 'fresh-notice-beta-unknown-contender');
    $unknownBlocked = runNoticeTenant(
        $unknownContender,
        'test.notice.verification.send.unknown-contender',
        fn() => $unknownService->send($unknownContender, 'login_code', '13800000007'),
    );
    expectNoticeTenant(!$unknownBlocked->success, 'unknown Provider result released the reservation');
    expectNoticeTenant($unknownSender->calls === 1, 'unknown Provider result was retried inside the reservation window');
    $unknownRow = $pdo->query(
        "SELECT status,reservation_active FROM pa_notice_log WHERE tenant_id = 202 AND receiver = '13800000007' ORDER BY id DESC LIMIT 1",
    )->fetch(PDO::FETCH_ASSOC);
    expectNoticeTenant(
        (int) ($unknownRow['status'] ?? -1) === NoticeLog::STATUS_UNKNOWN
            && (int) ($unknownRow['reservation_active'] ?? 0) === 1,
        'unknown Provider result lost its durable state or active reservation',
    );

    $alphaScene = (int) runNoticeTenant(
        $alpha,
        'test.notice.scenes.alpha.login-code',
        fn() => NoticeScene::where([])->where('code', 'login_code')->value('id'),
    );
    $betaScene = (int) runNoticeTenant(
        $beta,
        'test.notice.scenes.beta.login-code',
        fn() => NoticeScene::where([])->where('code', 'login_code')->value('id'),
    );
    $alphaResetScene = (int) runNoticeTenant(
        $alpha,
        'test.notice.scenes.alpha.reset-password',
        fn() => NoticeScene::where([])->where('code', 'reset_password')->value('id'),
    );
    $logData = static fn(int $sceneId, string $receiver, string $code): array => [
        'template_id' => 0,
        'scene_id' => $sceneId,
        'channel' => NoticeLog::CHANNEL_SMS,
        'provider' => 'fixture-provider',
        'receiver' => $receiver,
        'title' => 'Code',
        'content' => 'Code ****',
        'verify_code_hash' => VerificationCodeSecret::hash($code),
        'is_verified' => NoticeLog::VERIFIED_NO,
        'check_count' => 0,
        'verified_time' => 0,
        'status' => NoticeLog::STATUS_SUCCESS,
        'error' => '',
        'extra' => '{}',
        'send_time' => time(),
    ];
    runNoticeTenant(
        $alpha,
        'test.notice.logs.create.alpha.first',
        fn() => NoticeLog::create($logData($alphaScene, '13800000002', '4827')),
    );
    runNoticeTenant(
        $beta,
        'test.notice.logs.create.beta',
        fn() => NoticeLog::create($logData($betaScene, '13800000002', '4827')),
    );
    runNoticeTenant(
        $alpha,
        'test.notice.logs.create.alpha.second',
        fn() => NoticeLog::create($logData($alphaScene, '13800000003', '5938')),
    );

    // D01：同一签发记录只允许五次错误，耗尽后正确验证码也不能再消费。
    $limitedMobile = '13800000008';
    runNoticeTenant(
        $alpha,
        'test.notice.logs.create.alpha.limit',
        fn() => NoticeLog::create($logData($alphaScene, $limitedMobile, '4827')),
    );
    for ($attempt = 1; $attempt <= 5; $attempt++) {
        $rejected = runNoticeTenant(
            $alpha,
            'test.notice.verification.limit.wrong.' . $attempt,
            fn() => $service->verify($alpha, 'login_code', $limitedMobile, '0000'),
        );
        expectNoticeTenant(!$rejected->accepted, 'incorrect verification unexpectedly succeeded');
    }
    $limitedLog = runNoticeTenant(
        $alpha,
        'test.notice.logs.alpha.limit.count',
        fn() => NoticeLog::where([])->where('receiver', $limitedMobile)->findOrEmpty(),
    );
    expectNoticeTenant((int) $limitedLog->check_count === 5, 'failed verification count was not committed');
    $exhausted = runNoticeTenant(
        $alpha,
        'test.notice.verification.limit.exhausted',
        fn() => $service->verify($alpha, 'login_code', $limitedMobile, '4827'),
    );
    expectNoticeTenant(!$exhausted->accepted, 'exhausted verification accepted the correct code');
    expectNoticeTenant(
        (int) runNoticeTenant(
            $alpha,
            'test.notice.logs.alpha.limit.exhausted-count',
            fn() => NoticeLog::where([])->where('receiver', $limitedMobile)->value('check_count'),
        ) === 5,
        'exhausted verification changed the failure count',
    );

    // 新签发记录独立计数，旧记录因只检索最新签发而不能被消费。
    $resentMobile = '13800000009';
    runNoticeTenant($alpha, 'test.notice.logs.create.alpha.resend.old', fn() => NoticeLog::create($logData($alphaScene, $resentMobile, '1111')));
    runNoticeTenant($alpha, 'test.notice.logs.create.alpha.resend.new', fn() => NoticeLog::create($logData($alphaScene, $resentMobile, '2222')));
    $oldCode = runNoticeTenant($alpha, 'test.notice.verification.resend.old', fn() => $service->verify($alpha, 'login_code', $resentMobile, '1111'));
    expectNoticeTenant(!$oldCode->accepted, 'old verification code was accepted after resend');
    $newCode = runNoticeTenant($alpha, 'test.notice.verification.resend.new', fn() => $service->verify($alpha, 'login_code', $resentMobile, '2222'));
    expectNoticeTenant($newCode->accepted, $newCode->error);

    $entryLimiter = new VerificationAttemptRateLimiter();
    $entrySource = '198.51.100.8';
    for ($attempt = 1; $attempt <= 10; $attempt++) {
        $entryLimiter->recordFailure(new TenantSystemContext(101, 'notice.verification.verify', 'notice.verification.verify', 'limit-' . $attempt), 'login_code', $limitedMobile, $entrySource);
    }
    try {
        $entryLimiter->assertAllowed(new TenantSystemContext(101, 'notice.verification.verify', 'notice.verification.verify', 'limit-check'), 'login_code', $limitedMobile, $entrySource);
        throw new RuntimeException('verification entry limiter did not reject its configured boundary');
    } catch (BusinessException $exception) {
        expectNoticeTenant($exception->errorCode === 'MEMBER_VERIFICATION_RATE_LIMITED', 'verification entry limiter returned an unexpected error');
    }
    $entryLimiter->assertAllowed(new TenantSystemContext(101, 'notice.verification.verify', 'notice.verification.verify', 'limit-tenant'), 'login_code', $limitedMobile, '198.51.100.9');

    // 两个独立 PHP 进程竞争同一行：第 5 次错误提交后，另一个请求不能把计数越过上限。
    $concurrentWrongMobile = '13800000010';
    runNoticeTenant($alpha, 'test.notice.logs.create.alpha.concurrent-wrong', fn() => NoticeLog::create([
        ...$logData($alphaScene, $concurrentWrongMobile, '3333'),
        'check_count' => 4,
    ]));
    $wrongResults = noticeConcurrentVerifications($concurrentWrongMobile, '0000');
    expectNoticeTenant(count(array_filter($wrongResults, static fn(array $result): bool => $result['accepted'])) === 0, 'concurrent incorrect verification succeeded');
    expectNoticeTenant(
        (int) runNoticeTenant($alpha, 'test.notice.logs.alpha.concurrent-wrong-count', fn() => NoticeLog::where([])->where('receiver', $concurrentWrongMobile)->value('check_count')) === 5,
        'concurrent incorrect verification bypassed the database failure limit',
    );

    // 同一正确验证码只能被一个竞争请求消费。
    $concurrentCorrectMobile = '13800000011';
    runNoticeTenant($alpha, 'test.notice.logs.create.alpha.concurrent-correct', fn() => NoticeLog::create($logData($alphaScene, $concurrentCorrectMobile, '4444')));
    $correctResults = noticeConcurrentVerifications($concurrentCorrectMobile, '4444');
    expectNoticeTenant(count(array_filter($correctResults, static fn(array $result): bool => $result['accepted'])) === 1, 'concurrent correct verification was consumed more than once');
    expectNoticeTenant(
        (int) runNoticeTenant($alpha, 'test.notice.logs.alpha.concurrent-correct-consumed', fn() => NoticeLog::where([])->where('receiver', $concurrentCorrectMobile)->value('is_verified')) === NoticeLog::VERIFIED_YES,
        'concurrent correct verification did not persist consumption',
    );

    // 登录和重置均通过真实 LoginApplicationService 与验证码合同，而不是只调用验证码服务。
    $loginService = app(LoginApplicationService::class);
    $loginMobile = '13800000012';
    runNoticeTenant($alpha, 'test.notice.logs.create.alpha.login', fn() => NoticeLog::create($logData($alphaScene, $loginMobile, '5555')));
    $loginResult = runNoticeTenant(
        $alpha,
        'test.notice.login.mobile',
        fn() => $loginService->mobileLogin($alpha, ['mobile' => $loginMobile, 'code' => '5555'], '198.51.100.12'),
    );
    expectNoticeTenant((string) ($loginResult['mobile'] ?? '') === $loginMobile && (string) ($loginResult['token'] ?? '') !== '', 'mobile login did not consume the verified code through its application service');
    runNoticeTenant($alpha, 'test.notice.logs.create.alpha.reset', fn() => NoticeLog::create($logData($alphaResetScene, $loginMobile, '6666')));
    expectNoticeTenant(
        runNoticeTenant(
            $alpha,
            'test.notice.reset-password',
            fn() => $loginService->resetPassword($alpha, ['mobile' => $loginMobile, 'code' => '6666', 'password' => 'ResetPassword2026']),
        ),
        'password reset did not consume the verified code through its application service',
    );
    $resetHash = (string) runNoticeTenant($alpha, 'test.notice.member.reset-password', fn() => \PeanutAdmin\Modules\Member\Model\Member::where([])->where('mobile', $loginMobile)->value('password'));
    expectNoticeTenant(password_verify('ResetPassword2026', $resetHash), 'password reset did not update the member credential');

    // 路由注册、公共租户中间件和控制器限流依赖共同构成两个 HTTP 入口的装配合同。
    $memberRouteSource = (string) file_get_contents($serverRoot . '/app/modules/peanut/member/route/app.php');
    $loginControllerSource = (string) file_get_contents($serverRoot . '/app/modules/peanut/member/src/Controller/MemberLoginController.php');
    expectNoticeTenant(
        str_contains($memberRouteSource, "['login/mobile', 'mobile', 'notice.verification.verify', 'http.mobile-login']")
            && str_contains($memberRouteSource, "['login/resetPassword', 'resetPassword', 'notice.verification.verify', 'http.reset-password']")
            && str_contains($memberRouteSource, "PublicTenantModuleMiddleware::class, 'peanut.notice.verification'"),
        'verification HTTP routes lost their public tenant and notification module middleware',
    );
    expectNoticeTenant(
        substr_count($loginControllerSource, 'verificationAttempts->assertAllowed') === 2
            && substr_count($loginControllerSource, 'verificationAttempts->recordFailure') === 2,
        'verification HTTP controller did not wire rate protection for login and password reset',
    );

    $alphaVerification = runNoticeTenant(
        $alpha,
        'test.notice.verification.verify.alpha',
        fn() => $service->verify($alpha, 'login_code', '13800000002', '4827'),
    );
    expectNoticeTenant($alphaVerification->accepted, $alphaVerification->error);
    expectNoticeTenant(
        (int) runNoticeTenant(
            $beta,
            'test.notice.logs.beta.verification',
            fn() => NoticeLog::where([])->where('receiver', '13800000002')->value('is_verified'),
        ) === NoticeLog::VERIFIED_NO,
        'Alpha verification consumed Beta code',
    );
    $betaVerification = runNoticeTenant(
        $beta,
        'test.notice.verification.verify.beta',
        fn() => $service->verify($beta, 'login_code', '13800000002', '4827'),
    );
    expectNoticeTenant($betaVerification->accepted, $betaVerification->error);

    $crossTenantVerification = runNoticeTenant(
        $beta,
        'test.notice.verification.verify.cross-tenant',
        fn() => $service->verify($beta, 'login_code', '13800000003', '5938'),
    );
    expectNoticeTenant(!$crossTenantVerification->accepted, 'cross-tenant verification succeeded');
    $crossTenantError = $crossTenantVerification->error;
    $missingVerification = runNoticeTenant(
        $beta,
        'test.notice.verification.verify.missing',
        fn() => $service->verify($beta, 'login_code', '13800000004', '5938'),
    );
    expectNoticeTenant(!$missingVerification->accepted, 'missing verification unexpectedly succeeded');
    expectNoticeTenant($missingVerification->error === $crossTenantError, 'cross-tenant verification enumerated Alpha log');

    $alphaLogs = runNoticeTenant($alpha, 'test.notice.logs.alpha', fn() => $notifications->logs(['page' => 1, 'limit' => 50]));
    $betaLogs = runNoticeTenant($beta, 'test.notice.logs.beta', fn() => $notifications->logs(['page' => 1, 'limit' => 50]));
    expectNoticeTenant($alphaLogs->total === 10, 'Alpha admin log list missed an owned verification record or crossed Tenant boundary');
    expectNoticeTenant($betaLogs->total === 6, 'Beta admin log list crossed Tenant boundary');
    $betaLogId = (int) runNoticeTenant(
        $beta,
        'test.notice.logs.beta.latest',
        fn() => NoticeLog::where([])->order('id', 'desc')->value('id'),
    );
    expectNoticeTenant(runNoticeTenant($alpha, 'test.notice.log.cross', fn() => $notifications->logDetail($betaLogId)) === [], 'cross-tenant log detail was visible');
    expectNoticeTenant(runNoticeTenant($alpha, 'test.notice.log.missing', fn() => $notifications->logDetail(999999)) === [], 'missing log detail shape changed');

    echo json_encode([
        'status' => 'passed',
        'scope' => 'notice-tenant-isolation',
        'schema' => 'fresh-canonical',
        'tenant_isolation' => ['scene', 'template_key', 'send_log', 'verification', 'admin_log'],
        'provider_credentials' => 'application_host_only',
        'sms_reservation' => ['idempotent_replay', 'provider_barrier', 'failure_release', 'unknown_hold'],
        'assertions' => $noticeAssertions,
    ], JSON_UNESCAPED_SLASHES) . PHP_EOL;
} finally {
    RegisteredMysqlTestResource::cleanup($pdo, $database, $createdDatabase);
}
