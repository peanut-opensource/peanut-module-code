<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/route/registry_source.php';

use PeanutAdmin\Modules\Notification\Delivery\Application\VerificationCodeSecret;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

function expectNotificationHost(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$serverRoot = dirname(__DIR__, 2);
$repositoryRoot = dirname($serverRoot);

$codeHash = VerificationCodeSecret::hash('4827');
expectNotificationHost($codeHash !== '4827', 'verification code is stored in plaintext');
expectNotificationHost(VerificationCodeSecret::matches('4827', $codeHash), 'verification code hash cannot be verified');
expectNotificationHost(!VerificationCodeSecret::matches('4828', $codeHash), 'wrong verification code is accepted');

$channelService = (string) file_get_contents(
    $serverRoot . '/app/common/services/notice/NoticeChannelService.php',
);
foreach ([
    'new AliyunSms', 'new TencentSms', '$this->bindings->mutate(', 'safeReceipt', 'sanitizeError',
    "private const BINDING_PROVIDER = 'notice.sms'",
] as $marker) {
    expectNotificationHost(str_contains($channelService, $marker), 'SMS Host invariant missing: ' . $marker);
}
expectNotificationHost(
    !str_contains($channelService, 'external_channel_binding'),
    'SMS Host bypasses the External Channel binding owner',
);
$applicationService = (string) file_get_contents(
    $serverRoot . '/app/modules/peanut/notification/src/Service/NotificationApplicationService.php',
);
$notificationProvider = (string) file_get_contents(
    $serverRoot . '/app/modules/peanut/notification/src/ModuleProvider.php',
);
$sceneValidator = (string) file_get_contents(
    $serverRoot . '/app/modules/peanut/notification/src/Validation/NoticeSceneValidate.php',
);
$readinessHost = (string) file_get_contents(
    $serverRoot . '/app/common/services/readiness/FirstRunReadinessHost.php',
);
$readinessController = (string) file_get_contents(
    $serverRoot . '/app/adminapi/controller/config/ReadinessController.php',
);
expectNotificationHost(
    str_contains($notificationProvider, 'VerificationCodeCommands::class =>'),
    'verification command contract is not bound at startup',
);
expectNotificationHost(
    str_contains($sceneValidator, 'private readonly NotificationQueries $queries')
        && str_contains($sceneValidator, '$this->queries->sceneExists(')
        && !str_contains($sceneValidator, 'new ModuleProvider'),
    'scene validation bypasses the Notification query contract binding',
);
expectNotificationHost(
    str_contains($readinessHost, 'private readonly ?NotificationQueries $notifications')
        && str_contains($readinessHost, "NOTIFICATION_MODULE_UNAVAILABLE'))->channelDetail()")
        && !str_contains($readinessHost, 'NotificationModuleProvider')
        && str_contains($readinessController, 'protected string $readinessClass = FirstRunReadinessHost::class;')
        && str_contains($readinessController, '@property-read FirstRunReadinessHost $readiness')
        && str_contains($readinessController, '$this->readiness->checklist(')
        && !str_contains($readinessController, 'new FirstRunReadinessHost'),
    'readiness projection bypasses its container-owned Notification dependency',
);
foreach ([
    'Login' => '/app/modules/peanut/member/src/Service/LoginApplicationService.php',
    'OAuth' => '/app/modules/peanut/oauth/src/Service/OAuthCommandService.php',
    'Sms' => '/app/modules/peanut/member/src/Service/MemberVerificationService.php',
    'User' => '/app/modules/peanut/member/src/Service/UserApplicationService.php',
] as $application => $path) {
    $consumer = (string) file_get_contents($serverRoot . $path);
    expectNotificationHost(
        str_contains($consumer, 'VerificationCodeCommands')
            && (str_contains($consumer, '$this->verificationCodes->') || str_contains($consumer, '$this->verificationCodes ?? throw'))
            && !str_contains($consumer, '(new ModuleProvider())->verification()'),
        $application . ' consumer bypasses the Notification contract binding',
    );
}
expectNotificationHost(
    str_contains($applicationService, "NoticeLog::alias('l')->where([])")
        && !str_contains($applicationService, "where('l.tenant_id'"),
    'notification log reads do not rely on the global Tenant model scope',
);

$verificationService = (string) file_get_contents(
    $serverRoot . '/app/modules/peanut/notification/src/Service/VerificationCodeService.php',
);
foreach ([
    '$this->sender->send', "['code' => '****']", 'verify_code_hash',
    'NoticeLog::create', 'markProviderAttemptStarted',
    'SmsDriverResult::OUTCOME_UNKNOWN', 'reservation_active',
] as $marker) {
    expectNotificationHost(str_contains($verificationService, $marker), 'verification boundary missing: ' . $marker);
}
$applicationSender = (string) file_get_contents(
    $serverRoot . '/app/common/infrastructure/notice/ApplicationNoticeSmsSender.php',
);
$notificationProvider = (string) file_get_contents(
    $serverRoot . '/app/modules/peanut/notification/src/ModuleProvider.php',
);
expectNotificationHost(
    str_contains($applicationSender, '$this->channels->sendSms('),
    'tenant-owned notification flow does not delegate to the application credential Host',
);
expectNotificationHost(
    str_contains($notificationProvider, "Config::get('peanut.environment', '') === 'development'")
        && str_contains($applicationSender, 'private readonly bool $developmentMode')
        && str_contains($applicationSender, "'delivery' => 'simulated'")
        && !str_contains($applicationSender, 'getenv('),
    'development SMS delivery is not simulated before the External Channel Host',
);
expectNotificationHost(
    str_contains($verificationService, 'private readonly bool $developmentMode')
        && str_contains($verificationService, "? '1234'")
        && !str_contains($verificationService, 'getenv('),
    'development verification code is not fixed to 1234',
);
expectNotificationHost(
    !str_contains($channelService, 'ConfigService'),
    'SMS Host still reads or writes the global config table',
);
expectNotificationHost(
    preg_match('/\$this->sender->send\(\s*\$context,/', $verificationService) === 1,
    'verification flow does not pass its trusted Tenant context to the SMS Host',
);
expectNotificationHost(
    !str_contains($verificationService, "->where('is_verified', NoticeLog::VERIFIED_NO)"),
    'verification can fall back to an older code after the latest code is consumed',
);
expectNotificationHost(
    preg_match('/\(int\)\s*\$log->is_verified === NoticeLog::VERIFIED_YES/', $verificationService) === 1,
    'latest successful verification record is not checked for prior consumption',
);
foreach (['ConfigService::get', 'new AliyunSms', 'new TencentSms', "'verify_code' => \$code"] as $forbidden) {
    expectNotificationHost(!str_contains($verificationService, $forbidden), 'verification service bypasses Host: ' . $forbidden);
}

$logModel = (string) file_get_contents($serverRoot . '/app/modules/peanut/notification/src/Model/NoticeLog.php');
expectNotificationHost(
    str_contains($logModel, "protected \$hidden = ['verify_code_hash', 'extra']"),
    'secret hash or provider response can be serialized',
);
expectNotificationHost(!str_contains($applicationService, "field('l.*"), 'notification API exposes unrestricted log columns');
expectNotificationHost(!str_contains($applicationService, 'verify_code_hash'), 'notification API selects the verification hash');
expectNotificationHost(!str_contains($applicationService, "'l.extra'"), 'notification API selects raw provider results');

$routeSource = peanut_route_registry_source($serverRoot);
foreach (['notice/template/lists', 'notice/template/add', 'notice/template/edit', 'notice/template/delete'] as $route) {
    expectNotificationHost(!str_contains($routeSource, $route), 'retired generic template route remains: ' . $route);
}
foreach ([
    'app/common/service/notice/NoticeService.php',
    'app/common/service/notice/driver/mail/SmtpMail.php',
    'app/common/model/notice/NoticeTemplate.php',
    'app/adminapi/controller/notice/NoticeTemplateController.php',
    'app/adminapi/application/notice/NoticeTemplateLogic.php',
] as $retiredPath) {
    expectNotificationHost(!is_file($serverRoot . '/' . $retiredPath), 'retired notification Runtime remains: ' . $retiredPath);
}

$schema = (string) file_get_contents(
    $serverRoot . '/app/modules/peanut/notification/database/migrations/20261102000001_initial_notification_schema.php',
);
expectNotificationHost(
    preg_match('/`verify_code_hash`\s+varchar\(255\)/i', $schema) === 1
        && !preg_match('/`verify_code`\s+varchar/i', $schema),
    'fresh Schema does not define only the hashed verification code column',
);
expectNotificationHost(
    !str_contains($schema, 'CHANGE COLUMN `verify_code`')
        && !str_contains($schema, "REPLACE(`content`, `verify_code`, '****')"),
    'fresh Schema still contains legacy verification-code transition SQL',
);
// The owned initial migration carries the final reservation schema directly.
// Historical transitions, Edition projection and sealed external receipts are
// not runtime inputs of this module checkout.
foreach ([
    'uk_notice_sms_idempotency', 'uk_notice_sms_active_receiver',
    'reservation_until', 'idempotency_key_hash', 'reservation_active',
    '(`tenant_id`,`channel`,`receiver_hash`,`reservation_active`)',
    '`status` in (0,1,3)', 'fk_notice_log_tenant',
] as $marker) {
    expectNotificationHost(str_contains($schema, $marker), 'SMS reservation schema invariant missing: ' . $marker);
}
expectNotificationHost(!str_contains($verificationService, 'sentRecently('), 'verification flow still uses check-then-act rate limiting');

$tenantSources = [$channelService, $verificationService, $applicationService, $logModel];
foreach ($tenantSources as $source) {
    expectNotificationHost(
        !str_contains($source, 'PeanutAdmin\\Kernel\\Notification')
            && !str_contains($source, 'PeanutAdmin\\Kernel\\Notice'),
        'notification business ownership escaped its module',
    );
}
foreach ([$verificationService, $applicationSender] as $source) {
    foreach (['ConfigService::get', 'new AliyunSms', 'new TencentSms'] as $forbidden) {
        expectNotificationHost(!str_contains($source, $forbidden), 'tenant Runtime copied provider credential ownership');
    }
}

echo "PB07-NOTIFICATION-HOST-001 passed\n";
