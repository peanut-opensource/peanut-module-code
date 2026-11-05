<?php

declare(strict_types=1);

$root = dirname(__DIR__, 4);
require_once $root . '/vendor/autoload.php';
require_once $root . '/tests/Support/ThinkPhpTestConnection.php';
require_once $root . '/tests/Support/RegisteredMysqlTestResource.php';
require_once $root . '/tests/Support/IdentityMysqlFixture.php';

define('NOTIFICATION_MYSQL_DATABASE', RegisteredMysqlTestResource::configuredDatabaseName());
use PeanutAdmin\Kernel\Async\TrustedEnvelopeCodec;
use PeanutAdmin\Kernel\Async\AsyncAuthorizationRevalidator;
use PeanutAdmin\Kernel\Async\JobHandlerAdapter;
use PeanutAdmin\Kernel\Async\VerifiedJobEnvelope;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Auth\ValidatedTenantSession;
use PeanutAdmin\Kernel\Context\AuthorizationDecision;
use PeanutAdmin\Kernel\Context\AuthorizedOperationContext;
use PeanutAdmin\Modules\Identity\Membership\Query\ThinkPhpTenantMemberDirectory;
use think\facade\Db;
use PeanutAdmin\Modules\Notification\Delivery\Application\AttachmentReference;
use PeanutAdmin\Modules\Notification\Delivery\Application\AttachmentResolver;
use PeanutAdmin\Modules\Notification\Delivery\Application\NotificationException;
use PeanutAdmin\Modules\Notification\Delivery\Application\NotificationService;
use PeanutAdmin\Modules\Notification\Delivery\Application\RecipientResolver;
use PeanutAdmin\Modules\Notification\Delivery\Application\RecipientSnapshot;
use PeanutAdmin\Modules\Notification\Delivery\Application\TemplateRenderer;
use PeanutAdmin\Modules\Notification\Delivery\Database\Schema;
use PeanutAdmin\Modules\Notification\Delivery\Package;
use PeanutAdmin\Modules\Notification\Delivery\Persistence\NotificationStore;
use PeanutAdmin\Modules\Notification\Delivery\Sms\SmsRecipient;
use PeanutAdmin\Modules\Notification\Delivery\Task\NotificationOutboxDispatcher;
use PeanutAdmin\Modules\Notification\Delivery\Task\OutboxTaskSubmissionProvider;
use PeanutAdmin\Modules\Notification\Delivery\Task\InboxTaskHandler;
use PeanutAdmin\Modules\Task\Job\Database\Schema as TaskJobSchema;
use PeanutAdmin\Modules\Task\Contract\JobExecution;
use PeanutAdmin\Modules\Task\Contract\TaskHandler;
use PeanutAdmin\Modules\Task\Job\Execution\LocalWorker;
use PeanutAdmin\Modules\Task\Job\Execution\TaskHandlerRegistry;
use PeanutAdmin\Modules\Task\Job\Persistence\TaskJobStore;
use PeanutAdmin\Modules\Task\Job\Submission\TaskSubmissionRegistry;
use PeanutAdmin\Modules\Task\Contract\TrustedJobPublisher;

function same(mixed $expected, mixed $actual, string $message): void
{
    $GLOBALS['notificationMysqlChecks'] = ($GLOBALS['notificationMysqlChecks'] ?? 0) + 1;
    if ($expected !== $actual) {
        throw new RuntimeException($message . ': ' . var_export($actual, true));
    }
}

function guardedNotificationDatabase(PDO $pdo): string
{
    $database = $pdo->query('SELECT DATABASE()')->fetchColumn();
    if ($database !== NOTIFICATION_MYSQL_DATABASE) {
        throw new RuntimeException('Refusing destructive SQL outside the registered Task/Notification database.');
    }
    return $database;
}

function operation(string $name, int $tenantId, int $accountId, int $memberId): AuthorizedOperationContext
{
    $sessionKey = $tenantId === 101 ? '01J00000000000000000000000' : '01J00000000000000000000001';
    $tenant = $GLOBALS['notificationApplication']->make(\PeanutAdmin\Modules\Identity\Contract\TenantSessionAccess::class)
        ->revalidateSession($tenantId, $accountId, $memberId, $sessionKey, 'admin-web', 'req_b03_mysql_' . $tenantId);
    return AuthorizedOperationContext::fromDecision(AuthorizationDecision::allow(
        $tenant,
        Package::RESOURCE_KEY,
        $name,
        [],
        hash('sha256', 'basis-' . $tenantId . '-' . $name),
    ));
}

[$pdo, $createdDatabase] = RegisteredMysqlTestResource::openEmptyDatabase(NOTIFICATION_MYSQL_DATABASE);
$connection = ThinkPhpTestConnection::fromPdo($pdo);
same(NOTIFICATION_MYSQL_DATABASE, guardedNotificationDatabase($pdo), 'registered Task/Notification database selected');

$drop = array_reverse(Schema::tableNames());
$taskDrop = array_reverse(TaskJobSchema::tableNames());
try {
    guardedNotificationDatabase($pdo);
    IdentityMysqlFixture::install($pdo);
    $app = \think\Container::getInstance();
    if (!$app instanceof \think\App) {
        throw new RuntimeException('EXPLICIT_TEST_APPLICATION_REQUIRED');
    }
    $app->env->set(['DEPLOYMENT_MODE' => 'multi-tenant', 'APP_ENV' => 'development', 'APP_DEBUG' => 'true']);
    $app->initialize();
    $app->config->set(['signing_key' => str_repeat('e', 32)], 'async');
    ThinkPhpTestConnection::fromPdo($pdo);
    IdentityMysqlFixture::install($pdo, array_map(
        static fn(string $module): string => $root . '/app/modules/peanut/' . $module . '/database/migrations',
        ['settings', 'reference_codes', 'integration', 'task', 'notification'],
    ));
    $pdo->exec("INSERT INTO pa_account(id,display_name,status,created_at,updated_at) VALUES (301,'Tenant A member','active','2026-01-01 00:00:00.000','2026-01-01 00:00:00.000'),(302,'Tenant B member','active','2026-01-01 00:00:00.000','2026-01-01 00:00:00.000')");
    $pdo->exec("INSERT INTO pa_tenant(id,code,name,display_name,status,activated_at,created_at,updated_at) VALUES (101,'tenant-a','Tenant A','Tenant A','active','2026-01-01 00:00:00.000','2026-01-01 00:00:00.000','2026-01-01 00:00:00.000'),(202,'tenant-b','Tenant B','Tenant B','active','2026-01-01 00:00:00.000','2026-01-01 00:00:00.000','2026-01-01 00:00:00.000')");
    $pdo->exec("INSERT INTO pa_tenant_member(id,tenant_id,account_id,display_name,status,joined_at,created_at,updated_at) VALUES (501,101,301,'Tenant A member','active','2026-01-01 00:00:00.000','2026-01-01 00:00:00.000','2026-01-01 00:00:00.000'),(502,202,302,'Tenant B member','active','2026-01-01 00:00:00.000','2026-01-01 00:00:00.000','2026-01-01 00:00:00.000')");
    $pdo->exec("INSERT INTO pa_tenant_session(session_key,tenant_id,account_id,tenant_member_id,client_key,status,account_security_revision,tenant_security_revision,member_security_revision,issued_at,last_seen_at,idle_expires_at,absolute_expires_at,created_at,updated_at) VALUES ('01J00000000000000000000000',101,301,501,'admin-web','active',1,1,1,'2026-01-01 00:00:00.000','2026-01-01 00:00:00.000','2030-01-01 00:00:00.000','2030-01-02 00:00:00.000','2026-01-01 00:00:00.000','2026-01-01 00:00:00.000'),('01J00000000000000000000001',202,302,502,'admin-web','active',1,1,1,'2026-01-01 00:00:00.000','2026-01-01 00:00:00.000','2030-01-01 00:00:00.000','2030-01-02 00:00:00.000','2026-01-01 00:00:00.000','2026-01-01 00:00:00.000')");
    $GLOBALS['notificationApplication'] = $app;
    $registry = $app->make(\PeanutAdmin\Kernel\Module\CompiledModuleRegistry::class);
    $selected = ['peanut.identity', 'peanut.settings', 'peanut.reference-codes', 'peanut.integration', 'peanut.task', 'peanut.notification'];
    foreach ($selected as $moduleKey) {
        $manifest = $registry->requireManifest($moduleKey);
        $statement = $pdo->prepare('INSERT INTO pa_module_installation(module_key,installed_version,manifest_schema_version,manifest_digest,status,revision,installed_at,activated_at,created_at,updated_at) VALUES(?,?,?,?,"active",1,UTC_TIMESTAMP(3),UTC_TIMESTAMP(3),UTC_TIMESTAMP(3),UTC_TIMESTAMP(3))');
        $statement->execute([$moduleKey, $manifest->data['version'], $manifest->data['schema_version'], $manifest->digest]);
    }
    $app->make(\app\platform\infrastructure\module\ModuleCatalogApplier::class)->apply($registry, $selected);
    $pdo->exec("INSERT INTO pa_plan(id,code,name,status,revision,created_at,updated_at) VALUES(1,'notification-fixture','Notification fixture','active',1,UTC_TIMESTAMP(3),UTC_TIMESTAMP(3))");
    $statement = $pdo->prepare('INSERT INTO pa_plan_module(plan_id,module_key) VALUES(1,?)');
    foreach ($selected as $moduleKey) {
        $statement->execute([$moduleKey]);
    }
    $pdo->exec("INSERT INTO pa_plan_permission(plan_id,permission_key) SELECT 1,`key` FROM pa_permission WHERE status='active' AND `key` NOT LIKE 'platform.%'");
    foreach ([101 => 501, 202 => 502] as $tenantId => $memberId) {
        $pdo->exec("INSERT INTO pa_tenant_license(tenant_id,plan_id,status,effective_at,revision,created_at,updated_at) VALUES({$tenantId},1,'active',DATE_SUB(UTC_TIMESTAMP(3),INTERVAL 1 DAY),1,UTC_TIMESTAMP(3),UTC_TIMESTAMP(3))");
        $pdo->exec("INSERT INTO pa_role(id,tenant_id,`key`,name,is_builtin,status,created_at,updated_at) VALUES({$tenantId},{$tenantId},'notification-fixture','Notification fixture',0,'active',UTC_TIMESTAMP(3),UTC_TIMESTAMP(3))");
        $pdo->exec("INSERT INTO pa_member_role(tenant_id,tenant_member_id,role_id,assigned_at) VALUES({$tenantId},{$memberId},{$tenantId},UTC_TIMESTAMP(3))");
        $pdo->exec("INSERT INTO pa_role_permission(tenant_id,role_id,permission_id,granted_at) SELECT {$tenantId},{$tenantId},id,UTC_TIMESTAMP(3) FROM pa_permission WHERE `key` IN ('peanut.notification.delivery.manage','peanut.notification.inbox.read')");
    }
    $authorization = $app->make(\PeanutAdmin\Modules\Notification\Infrastructure\Authorization\NotificationAsyncAuthorization::class);

    $repository = new NotificationStore(new ThinkPhpTenantMemberDirectory());
    $digestKey = str_repeat('k', 32);
    $service = new NotificationService(
        $repository,
        new class ($digestKey) implements RecipientResolver {
            public function __construct(private readonly string $digestKey) {}
            public function snapshot(TenantContext $context, int $memberId, bool $requiresSms): RecipientSnapshot
            {
                $expected = $context->tenantId === 101 ? [501, 301, '+8613800138000'] : [502, 302, '+8613900139000'];
                if ($memberId !== $expected[0]) {
                    throw NotificationException::recipientUnavailable();
                }
                $sms = $requiresSms ? new SmsRecipient($expected[2], $this->digestKey) : null;
                return new RecipientSnapshot($expected[0], $expected[1], 'Tenant member', $sms?->masked, $sms?->digest);
            }
        },
        new class implements AttachmentResolver {
            public function snapshot(TenantContext $context, string $fileKey): AttachmentReference
            {
                if ($context->tenantId !== 101 || $fileKey !== 'file_' . str_repeat('f', 32)) {
                    throw NotificationException::attachmentUnavailable();
                }
                return new AttachmentReference($fileKey, 'report.pdf', 'application/pdf', 42, str_repeat('a', 64));
            }
        },
        new TemplateRenderer(),
        $app->make(\PeanutAdmin\Modules\Notification\Contract\NotificationProducerRegistry::class),
        $app->make(\PeanutAdmin\Kernel\Module\ModuleAvailability::class),
        $app->make(InboxTaskHandler::class),
    );

    $manage101 = operation('manage', 101, 301, 501);
    $read101 = operation('read', 101, 301, 501);
    $read202 = operation('read', 202, 302, 502);
    $template = $service->putTemplate(
        $manage101,
        'security.alert',
        'Security alert',
        'Alert {{code}}',
        'Review {{code}}',
        ['inbox', 'sms'],
        ['code'],
        null,
    );
    same(1, $template['revision'], 'template revision');
    $created = $service->publish($manage101, 'security.alert', [[
        'member_id' => 501,
        'variables' => ['code' => 'A-42'],
    ]], ['file_' . str_repeat('f', 32)]);
    same(1, count($created['messages']), 'message count');
    same(2, count($created['outbox']), 'outbox count');
    same(1, $created['messages'][0]->templateRevision, 'message template revision snapshot');

    $inbox101 = $service->inbox($read101, 'all', 1, 20);
    $inbox202 = $service->inbox($read202, 'all', 1, 20);
    same(0, $inbox101['total'], 'undelivered notification is not visible in own inbox');
    same(0, $inbox202['total'], 'cross-Tenant inbox isolation');

    $recipientDigest = (new SmsRecipient('+8613800138000', $digestKey))->digest;
    for ($attempt = 0; $attempt < 5; ++$attempt) {
        same(true, $repository->reserveSmsRate(101, $recipientDigest), 'recipient rate allowance');
    }
    same(false, $repository->reserveSmsRate(101, $recipientDigest), 'recipient rate bound');
    same(1, (int) $pdo->query('SELECT COUNT(*) FROM pa_notification_template')->fetchColumn(), 'template row');
    same(1, (int) $pdo->query('SELECT COUNT(*) FROM pa_notification_message WHERE template_revision = 1')->fetchColumn(), 'message row');

    $publisher = new TrustedJobPublisher(
        new TaskJobStore(),
        new TaskSubmissionRegistry([
            new OutboxTaskSubmissionProvider('inbox', $authorization, $app->make(\PeanutAdmin\Modules\Notification\Delivery\Task\NotificationTaskWorkerDefinition::class)),
            new OutboxTaskSubmissionProvider('sms', $authorization, $app->make(\PeanutAdmin\Modules\Notification\Delivery\Task\NotificationTaskWorkerDefinition::class)),
        ]),
        new TrustedEnvelopeCodec(str_repeat('e', 32)),
        $app->make(\PeanutAdmin\DataPermission\Catalog\ResourceOperationCatalog::class),
        $app->make(\PeanutAdmin\Modules\Identity\Module\TenantEntitlementService::class),
    );
    $dispatcher = new NotificationOutboxDispatcher($repository, $publisher);
    $inboxOutbox = null;
    foreach ($created['outbox'] as $outbox) {
        if ($outbox->channel === 'inbox') {
            $inboxOutbox = $outbox;
            break;
        }
    }
    if ($inboxOutbox === null) {
        throw new RuntimeException('published notification did not create an inbox outbox');
    }
    $inboxJob = $dispatcher->dispatch($manage101, $inboxOutbox->outboxKey);
    $replayedInboxJob = $dispatcher->dispatch($manage101, $inboxOutbox->outboxKey);
    same($inboxJob->jobKey, $replayedInboxJob->jobKey, 'inbox dispatch idempotency');
    same(1, (int) $pdo->query('SELECT COUNT(*) FROM pa_task_job')->fetchColumn(), 'inbox dispatch creates one job');
    $revalidator = new class ($authorization) implements AsyncAuthorizationRevalidator {
        public function __construct(private readonly \PeanutAdmin\Modules\Notification\Infrastructure\Authorization\NotificationAsyncAuthorization $authorization) {}
        public function reauthorize(VerifiedJobEnvelope $envelope): AuthorizedOperationContext
        {
            return $this->authorization->reauthorize($envelope);
        }
    };
    $inboxHandler = new InboxTaskHandler($repository, $authorization);
    $leaseLosingHandler = new class ($pdo, $inboxHandler) implements TaskHandler {
        public function __construct(private readonly PDO $pdo, private readonly InboxTaskHandler $inner) {}
        public function key(): string
        {
            return $this->inner->key();
        }
        public function handle(AuthorizedOperationContext $context, JobExecution $execution): void
        {
            $statement = $this->pdo->prepare(
                'UPDATE pa_task_job SET lease_expires_at=TIMESTAMPADD(SECOND,-1,UTC_TIMESTAMP(3)) WHERE job_key=?',
            );
            $statement->execute([$execution->jobKey]);
            $this->inner->handle($context, $execution);
        }
    };
    // Explicit fixture lifecycle; native Runtime/CLI integration is covered separately.
    $jobContexts = new \app\common\execution\ExecutionContextStore();
    $jobRoot = static function ($context, $claim, \Closure $handle) use ($jobContexts): void {
        if (!$jobContexts->isEmpty()) {
            throw new \DomainException('TASK_EXECUTION_ROOT_REQUIRED');
        }
        $jobContexts->run(new \app\common\execution\SystemExecutionContext(
            new \PeanutAdmin\Kernel\Context\TenantSystemContext($claim->tenantId, 'task-worker', 'async.worker', $claim->jobKey),
            new \app\common\execution\SystemExecutionMetadata($claim->jobKey, $claim->attemptNumber, $claim->handlerKey),
        ), $handle);
    };
    $lostWorker = new LocalWorker(
        101,
        'notification-lease-loss',
        new TaskJobStore(),
        new TaskHandlerRegistry([$leaseLosingHandler]),
        new JobHandlerAdapter(new TrustedEnvelopeCodec(str_repeat('e', 32)), $revalidator),
        $jobRoot,
        30,
    );
    same('lease_lost', $lostWorker->runOnce(), 'expired inbox claim is fenced');
    same('queued', $pdo->query(
        'SELECT status FROM pa_notification_outbox WHERE outbox_key=' . $pdo->quote($inboxOutbox->outboxKey),
    )->fetchColumn(), 'lost claim leaves inbox outbox queued');
    $recoveryWorker = new LocalWorker(
        101,
        'notification-lease-recovery',
        new TaskJobStore(),
        new TaskHandlerRegistry([$inboxHandler]),
        new JobHandlerAdapter(new TrustedEnvelopeCodec(str_repeat('e', 32)), $revalidator),
        $jobRoot,
        30,
    );
    same('succeeded', $recoveryWorker->runOnce(), 'expired inbox claim is recovered and consumed');
    same(null, $recoveryWorker->runOnce(), 'delivered inbox job is not consumed twice');
    same('delivered', $pdo->query(
        'SELECT status FROM pa_notification_outbox WHERE outbox_key=' . $pdo->quote($inboxOutbox->outboxKey),
    )->fetchColumn(), 'inbox outbox delivered after recovery');
    same(1, (int) $pdo->query(
        "SELECT COUNT(*) FROM pa_notification_event WHERE event_key='tenant.notification.delivered'",
    )->fetchColumn(), 'inbox delivery event is de-duplicated');
    $inbox101 = $service->inbox($read101, 'all', 1, 20);
    same(1, $inbox101['total'], 'own delivered Tenant inbox');
    same(0, $service->inbox($read202, 'all', 1, 20)['total'], 'delivered notification remains isolated from another Tenant');
    $read = $service->markRead($read101, $inbox101['items'][0]->messageKey, 1);
    same('read', $read->status, 'read transition');
    same(1, $service->bulk($read101, [$read->messageKey], 'archive'), 'archive transition');

    $messageCount = (int) $pdo->query('SELECT COUNT(*) FROM pa_notification_message')->fetchColumn();
    $outboxCount = (int) $pdo->query('SELECT COUNT(*) FROM pa_notification_outbox')->fetchColumn();
    $notificationEventCount = (int) $pdo->query('SELECT COUNT(*) FROM pa_notification_event')->fetchColumn();
    try {
        Db::transaction(function () use ($service, $manage101, $dispatcher, $pdo): never {
            $transactional = $service->publish($manage101, 'security.alert', [[
                'member_id' => 501,
                'variables' => ['code' => 'ROLLBACK'],
            ]], []);
            foreach ($transactional['outbox'] as $outbox) {
                $dispatcher->dispatch($manage101, $outbox->outboxKey);
            }
            same(2, (int) $pdo->query('SELECT COUNT(*) FROM pa_notification_message')->fetchColumn(), 'outer transaction sees notification');
            same(3, (int) $pdo->query('SELECT COUNT(*) FROM pa_task_job')->fetchColumn(), 'outer transaction sees existing and transactional dispatch jobs');
            same(2, (int) $pdo->query("SELECT COUNT(*) FROM pa_notification_outbox WHERE status = 'queued'")->fetchColumn(), 'outer transaction binds dispatch jobs');
            throw new RuntimeException('EXPECTED_OUTER_ROLLBACK');
        });
    } catch (RuntimeException $exception) {
        same('EXPECTED_OUTER_ROLLBACK', $exception->getMessage(), 'outer transaction rollback sentinel');
    }
    same($messageCount, (int) $pdo->query('SELECT COUNT(*) FROM pa_notification_message')->fetchColumn(), 'outer rollback removes notification');
    same($outboxCount, (int) $pdo->query('SELECT COUNT(*) FROM pa_notification_outbox')->fetchColumn(), 'outer rollback removes outbox rows');
    same(1, (int) $pdo->query('SELECT COUNT(*) FROM pa_task_job')->fetchColumn(), 'outer rollback removes only transactional dispatch jobs');
    same(5, (int) $pdo->query('SELECT COUNT(*) FROM pa_task_job_event')->fetchColumn(), 'outer rollback removes transactional task events');
    same($notificationEventCount, (int) $pdo->query('SELECT COUNT(*) FROM pa_notification_event')->fetchColumn(), 'outer rollback removes notification event');

    if (function_exists('notificationFeatureCases')) {
        notificationFeatureCases($pdo, $repository, $service, $manage101, $authorization, $publisher);
    }

    fwrite(STDOUT, 'notification-sms MySQL harness: PASS (' . ($GLOBALS['notificationMysqlChecks'] ?? 0) . " checks)\n");
} finally {
    RegisteredMysqlTestResource::cleanup($pdo, NOTIFICATION_MYSQL_DATABASE, $createdDatabase);
}
