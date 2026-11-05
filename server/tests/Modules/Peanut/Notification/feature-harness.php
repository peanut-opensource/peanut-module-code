<?php

declare(strict_types=1);

$root = dirname(__DIR__, 4);
require_once $root . '/vendor/autoload.php';
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Auth\ValidatedTenantSession;
use PeanutAdmin\Kernel\Context\AuthorizationDecision;
use PeanutAdmin\Kernel\Context\AuthorizedOperationContext;
use PeanutAdmin\Modules\Notification\Delivery\Application\AttachmentReference;
use PeanutAdmin\Modules\Notification\Delivery\Application\AttachmentResolver;
use PeanutAdmin\Modules\Notification\Delivery\Application\NotificationException;
use PeanutAdmin\Modules\Notification\Delivery\Application\NotificationMessage;
use PeanutAdmin\Modules\Notification\Delivery\Application\NotificationService;
use PeanutAdmin\Modules\Notification\Delivery\Application\OutboxRecord;
use PeanutAdmin\Modules\Notification\Delivery\Application\RecipientResolver;
use PeanutAdmin\Modules\Notification\Delivery\Application\RecipientSnapshot;
use PeanutAdmin\Modules\Notification\Delivery\Application\TemplateRenderer;
use PeanutAdmin\Modules\Notification\Delivery\Database\Schema;
use PeanutAdmin\Modules\Notification\Delivery\Package;
use PeanutAdmin\Modules\Notification\Delivery\Persistence\NotificationStore;
use PeanutAdmin\Modules\Notification\Delivery\Persistence\SmsDispatch;
use PeanutAdmin\Modules\Notification\Delivery\Sms\LocalDevSmsProvider;
use PeanutAdmin\Modules\Notification\Delivery\Sms\SmsProvider;
use PeanutAdmin\Modules\Notification\Delivery\Sms\SmsProviderException;
use PeanutAdmin\Modules\Notification\Delivery\Sms\SmsReceipt;
use PeanutAdmin\Modules\Notification\Delivery\Sms\SmsRecipient;
use PeanutAdmin\Modules\Notification\Delivery\Sms\SmsRecipientResolver;
use PeanutAdmin\Modules\Notification\Delivery\Task\InboxTaskHandler;
use PeanutAdmin\Modules\Notification\Delivery\Task\OutboxTaskSubmissionProvider;
use PeanutAdmin\Modules\Notification\Delivery\Task\SmsTaskHandler;
use PeanutAdmin\Modules\Task\Contract\JobExecution;
use PeanutAdmin\Modules\Task\Contract\RetryableTaskException;

function featureSame(mixed $expected, mixed $actual, string $message): void
{
    $GLOBALS['notificationFeatureChecks'] = ($GLOBALS['notificationFeatureChecks'] ?? 0) + 1;
    if ($expected !== $actual) {
        throw new RuntimeException($message);
    }
}

function expectCode(string $code, callable $operation, string $message): void
{
    try {
        $operation();
    } catch (NotificationException $exception) {
        featureSame($code, $exception->problemCode, $message);
        return;
    }
    throw new RuntimeException($message . ' did not fail');
}

$renderer = new TemplateRenderer();
featureSame('Hello Ada, code 42', $renderer->render('Hello {{name}}, code {{code}}', ['name', 'code'], ['name' => 'Ada', 'code' => 42], 100), 'strict render');
expectCode('NOTIFICATION_TEMPLATE_VARIABLE_INVALID', fn() => $renderer->render('{{name}}', ['name'], ['name' => 'Ada', 'extra' => 'x'], 100), 'unknown variable');
expectCode('NOTIFICATION_TEMPLATE_VARIABLE_INVALID', fn() => $renderer->render('{{name}}', ['name'], ['name' => '{{secret}}'], 100), 'recursive template injection');
expectCode('NOTIFICATION_TEMPLATE_VARIABLE_INVALID', fn() => $renderer->render('{{first}} {{second}}', ['first', 'second'], ['first' => '{{second}}', 'second' => 'secret'], 100), 'multi-variable recursive injection');

$recipient = new SmsRecipient('+8613800138000', str_repeat('k', 32));
featureSame('+861*******000', $recipient->masked, 'phone mask');
if (str_contains(json_encode($recipient, JSON_THROW_ON_ERROR), '+8613800138000')) {
    throw new RuntimeException('raw phone serialized');
}
$provider = new LocalDevSmsProvider();
$request = new \PeanutAdmin\Modules\Notification\Delivery\Sms\SmsSendRequest('job_' . str_repeat('a', 32), 101, 'outbox_' . str_repeat('2', 32), '+8613800138000', 'Test body');
featureSame('{}', json_encode($request, JSON_THROW_ON_ERROR), 'provider request is not implicitly serializable');
featureSame($provider->send($request)->providerMessageKey, $provider->send($request)->providerMessageKey, 'provider idempotency');
featureSame(1, $provider->acceptedCount(), 'provider deduplicates job key');

/** Exercises real persistence; temporary constraints reject writes at the owned database boundary. */
function notificationFeatureCases(
    PDO $pdo,
    NotificationStore $repository,
    NotificationService $service,
    AuthorizedOperationContext $manage,
    \PeanutAdmin\Modules\Notification\Infrastructure\Authorization\NotificationAsyncAuthorization $authorization,
    \PeanutAdmin\Modules\Task\Contract\TrustedJobPublisher $publisher,
): void {
    $read = operation('read', 101, 301, 501);
    expectCode('NOTIFICATION_PERMISSION_DENIED', fn() => $service->publish($read, 'security.alert', [['member_id' => 501, 'variables' => ['code' => 'x']]]), 'permission boundary');
    expectCode('NOTIFICATION_ATTACHMENT_UNAVAILABLE', fn() => $service->publish($manage, 'security.alert', [['member_id' => 501, 'variables' => ['code' => 'x']]], ['file_' . str_repeat('e', 32)]), 'attachment tenant/ready boundary');
    expectCode('NOTIFICATION_TEMPLATE_INVALID', fn() => $service->putTemplate($manage, 'security.alert', 'Alert', '{{code}}', '{{code}}', ['inbox'], ['code', 'code'], null), 'duplicate template variables');
    expectCode('NOTIFICATION_TEMPLATE_INVALID', fn() => $service->putTemplate($manage, 'security.alert', 'Alert', '{{v0}}', '{{v0}}', ['inbox'], array_map(static fn(int $index): string => 'v' . $index, range(0, 32)), null), 'template variable bound');
    $resolver = new class implements SmsRecipientResolver {
        public function resolve(TenantContext $context, int $memberId): SmsRecipient
        {
            if ($context->tenantId !== 101 || $context->accountId !== 301 || $context->memberId !== 501 || $memberId !== 501) {
                throw NotificationException::recipientUnavailable();
            }
            return new SmsRecipient('+8613800138000', str_repeat('k', 32));
        }
    };
    $foreign = operation('manage', 202, 302, 502)->tenantContext;
    expectCode('NOTIFICATION_RECIPIENT_UNAVAILABLE', fn() => $resolver->resolve($foreign, 501), 'SMS recipient lookup revalidates actor identity');
    $sequence = 0;
    $fresh = static function (string $channel = 'sms') use ($pdo, $service, $manage, $repository, &$sequence): JobExecution {
        $pdo->exec('DELETE FROM pa_sms_rate_bucket');
        $published = $service->publish($manage, 'security.alert', [['member_id' => 501, 'variables' => ['code' => 'FEATURE-' . ++$sequence]]]);
        foreach ($published['outbox'] as $outbox) {
            if ($outbox->channel !== $channel) {
                continue;
            }
            $job = 'job_' . str_pad(dechex($sequence), 32, '0', STR_PAD_LEFT);
            \think\facade\Db::transaction(fn() => $repository->bindJob(101, $outbox->outboxKey, $job));
            $lease = static function (): void {};
            return new JobExecution($job, 101, 1, ['outbox_key' => $outbox->outboxKey], $lease, $lease);
        }
        throw new RuntimeException('Missing requested channel');
    };
    $failure = static function (JobExecution $execution) use ($pdo): array {
        $statement = $pdo->prepare('SELECT status,last_error_code,provider_receipt_code FROM pa_notification_outbox WHERE outbox_key=?');
        $statement->execute([$execution->payload['outbox_key']]);
        return $statement->fetch(PDO::FETCH_ASSOC);
    };
    $retry = static function (string $code, callable $call): void {
        try {
            $call();
        } catch (RetryableTaskException $error) {
            featureSame($code, $error->safeCode, 'retry classification');
            return;
        }
        throw new RuntimeException('Expected retry ' . $code);
    };
    $fault = static function (JobExecution $execution, string $status, callable $call) use ($pdo): void {
        $key = $pdo->quote($execution->payload['outbox_key']);
        $rejectedStatus = $pdo->quote($status);
        // Target only this fixture row; earlier delivered/retryable rows remain valid.
        $pdo->exec("ALTER TABLE pa_notification_outbox ADD CONSTRAINT notification_feature_fault CHECK (NOT (outbox_key={$key} AND status={$rejectedStatus}))");
        try {
            $call();
        } finally {
            $pdo->exec('ALTER TABLE pa_notification_outbox DROP CHECK notification_feature_fault');
        }
    };
    $provider = new LocalDevSmsProvider();
    $execution = $fresh();
    $handler = new SmsTaskHandler($repository, $resolver, $provider);
    $handler->handle($manage, $execution);
    featureSame('DEV_ACCEPTED', $failure($execution)['provider_receipt_code'], 'redacted dev receipt');
    $handler->handle($manage, $execution);
    featureSame(1, $provider->acceptedCount(), 'delivered replay does not send twice');

    $execution = $fresh();
    $digest = (new SmsRecipient('+8613800138000', str_repeat('k', 32)))->digest;
    for ($index = 0; $index < 5; ++$index) {
        \think\facade\Db::transaction(fn() => $repository->reserveSmsRate(101, $digest));
    }
    $retry('SMS_RATE_LIMITED', fn() => $handler->handle($manage, $execution));
    featureSame(['retryable', 'SMS_RATE_LIMITED'], array_values(array_intersect_key($failure($execution), array_flip(['status', 'last_error_code']))), 'rate failure persisted');

    $execution = $fresh();
    $transient = new SmsTaskHandler($repository, new class implements SmsRecipientResolver {
        public function resolve(TenantContext $context, int $memberId): SmsRecipient
        {
            throw new RuntimeException('private lookup detail');
        }
    }, new LocalDevSmsProvider());
    $fault($execution, 'retryable', fn() => $retry('SMS_RECIPIENT_LOOKUP_FAILED', fn() => $transient->handle($manage, $execution)));
    featureSame(null, $failure($execution)['last_error_code'], 'failure-write error does not mask lookup retry class');

    $execution = $fresh();
    $fault($execution, 'processing', fn() => $retry('SMS_OUTBOX_PERSISTENCE_FAILED', fn() => $handler->handle($manage, $execution)));
    featureSame('queued', $failure($execution)['status'], 'failed begin rolls back');

    $execution = $fresh('inbox');
    $inbox = new InboxTaskHandler($repository, $authorization);
    $fault($execution, 'delivered', fn() => $retry('NOTIFICATION_INBOX_PERSISTENCE_FAILED', fn() => $inbox->handle($manage, $execution)));
    featureSame('queued', $failure($execution)['status'], 'failed inbox delivery rolls back');

    $execution = $fresh();
    $permanent = new SmsTaskHandler($repository, $resolver, new class implements SmsProvider {
        public function key(): string
        {
            return 'test-provider';
        }
        public function send(\PeanutAdmin\Modules\Notification\Delivery\Sms\SmsSendRequest $request): SmsReceipt
        {
            throw SmsProviderException::permanent('SMS_DESTINATION_REJECTED');
        }
    });
    expectCode('SMS_DESTINATION_REJECTED', fn() => $permanent->handle($manage, $execution), 'permanent provider code');
    featureSame('permanent_failed', $failure($execution)['status'], 'permanent provider classification');
    featureSame('SMS_DESTINATION_REJECTED', $failure($execution)['last_error_code'], 'permanent provider failure persisted');

    $execution = $fresh();
    $fault($execution, 'delivered', fn() => $retry('SMS_DELIVERY_COMMIT_UNKNOWN', fn() => $handler->handle($manage, $execution)));
    featureSame('retryable', $failure($execution)['status'], 'unknown delivery state persisted');
    featureSame('SMS_DELIVERY_COMMIT_UNKNOWN', $failure($execution)['last_error_code'], 'unknown delivery error persisted');
    $handler->handle($manage, $execution);
    featureSame('delivered', $failure($execution)['status'], 'unknown commit recovers through same idempotency key');

    featureSame(6, count(Schema::tableNames()), 'owned delivery table count');
    $schema = implode("\n", array_map(Schema::createSql(...), Schema::tableNames()));
    foreach (['tenant_id', 'recipient_phone_masked', 'recipient_phone_digest', 'dispatch_job_key', 'pa_sms_rate_bucket'] as $needle) {
        featureSame(true, str_contains($schema, $needle), 'schema retains ' . $needle);
    }
    featureSame(false, str_contains($schema, 'phone_e164') || str_contains($schema, 'provider_secret'), 'schema stores no raw phone or secret');
}

require __DIR__ . '/mysql-harness.php';
fwrite(STDOUT, 'notification feature harness: PASS (' . ($GLOBALS['notificationFeatureChecks'] ?? 0) . " checks, real_mysql=true)\n");
