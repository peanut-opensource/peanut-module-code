<?php

declare(strict_types=1);

use app\common\execution\ExecutionContextStore;
use PeanutAdmin\Modules\Payment\Service\RefundEnum;
use app\common\contract\idempotency\IdempotentCommandExecutor;
use app\common\contract\idempotency\IdempotencyCommand;
use app\common\contract\idempotency\IdempotencyReceipt;
use app\common\contract\idempotency\IdempotencyResult;
use PeanutAdmin\Modules\Payment\Infrastructure\Channel\RefundGatewayInterface;
use app\common\exception\BusinessException;
use PeanutAdmin\Modules\File\Service\FileService;
use PeanutAdmin\Modules\Payment\Infrastructure\PaymentRetryLock;
use PeanutAdmin\Modules\Payment\Infrastructure\Channel\PaymentServiceFactory;
use app\common\services\XlsxExportService;
use PeanutAdmin\Modules\Member\Contract\MemberBalanceCommands;
use PeanutAdmin\Modules\Member\Contract\MemberQueries;
use PeanutAdmin\Modules\Payment\Service\RechargeAdministrationService;
use PeanutAdmin\Modules\Payment\Model\RechargeOrder;
use PeanutAdmin\Modules\Payment\Model\RefundLog;
use PeanutAdmin\Modules\Payment\Model\RefundRecord;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Auth\ValidatedTenantSession;
use PeanutAdmin\Kernel\Tenancy\ScheduledTenantContext;
use PeanutAdmin\Kernel\Tenancy\TenantScope;
use think\facade\Console;

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

function expectFinanceTenant(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function financeTenantContext(int $tenantId, int $memberId, string $requestId): TenantContext
{
    return TenantContext::fromValidatedSession(new ValidatedTenantSession(
        $memberId,
        '01JMT03FINANCE' . str_pad((string) $memberId, 13, '0', STR_PAD_LEFT),
        $tenantId,
        $memberId + 10000,
        $memberId,
        'admin-web',
        new DateTimeImmutable('2031-01-01T00:00:00Z'),
        1,
        1,
    ), $requestId);
}

function financeRun(TenantContext $context, string $operation, callable $callback): mixed
{
    return app(ExecutionContextStore::class)->run(
        new \app\common\execution\AdminExecutionContext($context, $operation),
        $callback,
    );
}

function financeReconcile(TenantContext $context): void
{
    $scope = TenantScope::fromTrustedContext($context->tenantId, 'refund-test:' . $context->requestId);
    financeRun($context, 'test.refund.reconcile', static fn() => ScheduledTenantContext::run(
        $scope,
        static fn() => Console::call('refund:reconcile'),
    ));
}

function financeRefundService(
    XlsxExportService $xlsx,
    IdempotentCommandExecutor $idempotency,
): RechargeAdministrationService {
    return new RechargeAdministrationService(
        $xlsx,
        $idempotency,
        app(PaymentRetryLock::class),
        app(PaymentServiceFactory::class),
        app(FileService::class),
        app(MemberBalanceCommands::class),
        app(MemberQueries::class),
    );
}

// Replace only the external transport. The real PaymentServiceFactory still
// resolves the recorded binding and verifies its merchant identity before use.
final class RefundBehaviorGateway implements \PeanutAdmin\Modules\Payment\Infrastructure\Channel\PaymentChannelGateway
{
    public function __construct(string $channel, array $config) {}
    public function prepay(\PeanutAdmin\Modules\Payment\Infrastructure\Channel\PrepayRequest $request): \PeanutAdmin\Modules\Payment\Infrastructure\Channel\PrepayResult { throw new LogicException('prepay is outside this refund fixture'); }
    public function parse(\PeanutAdmin\Modules\Payment\Infrastructure\Channel\CallbackRequest $request): \PeanutAdmin\Modules\Payment\Infrastructure\Channel\PaymentEvent { throw new LogicException('callback is outside this refund fixture'); }
    public function queryPayment(string $number): array { throw new LogicException('payment query is outside this refund fixture'); }
    public function close(string $number): void { throw new LogicException('close is outside this refund fixture'); }
    /** @var array<string,string> */
    private static array $modes = [];
    /** @var array<string,list<string>> */
    public static array $refundKeys = [];
    /** @var array<string,list<string>> */
    public static array $queryKeys = [];
    /** @var array<string,int> */
    public static array $effects = [];

    public static function mode(string $orderSn, string $mode): void
    {
        self::$modes[$orderSn] = $mode;
    }

    public function refund(array $order, string $refundSn, int $refundAmountCents): array
    {
        $orderSn = (string) ($order['sn'] ?? '');
        self::$refundKeys[$orderSn][] = $refundSn;
        $attempt = count(self::$refundKeys[$orderSn]);
        $mode = self::$modes[$orderSn] ?? 'success';
        if ($mode === 'accepted_unknown') {
            self::$effects[$refundSn] = (self::$effects[$refundSn] ?? 0) + 1;
            throw new RuntimeException('provider accepted before transport failure', self::ERROR_RESULT_UNKNOWN);
        }
        if ($mode === 'known_failure_then_success' && $attempt === 1) {
            throw new RuntimeException('provider rejected refund');
        }
        self::$effects[$refundSn] = (self::$effects[$refundSn] ?? 0) + 1;
        return [
            'status' => self::STATUS_SUCCESS,
            'transaction_id' => 'provider-' . $refundSn,
            'receipt' => ['refund_sn' => $refundSn],
        ];
    }

    public function query(array $order, string $refundSn): array
    {
        $orderSn = (string) ($order['sn'] ?? '');
        self::$queryKeys[$orderSn][] = $refundSn;
        return [
            'status' => self::STATUS_SUCCESS,
            'transaction_id' => 'provider-' . $refundSn,
            'receipt' => ['refund_sn' => $refundSn],
        ];
    }
}

final readonly class FailingRefundIdempotency implements IdempotentCommandExecutor
{
    public function __construct(private IdempotentCommandExecutor $delegate) {}

    public function begin(IdempotencyCommand $command): IdempotencyResult
    {
        return $this->delegate->begin($command);
    }

    public function complete(IdempotencyResult $execution, IdempotencyReceipt $receipt): void
    {
        throw new RuntimeException('fixture receipt finalize failure');
    }

    public function fail(IdempotencyResult $execution, IdempotencyReceipt $receipt): void
    {
        throw new RuntimeException('fixture receipt finalize failure');
    }
}

if (!class_alias(RefundBehaviorGateway::class, 'PeanutAdmin\Modules\Payment\Infrastructure\Channel\YansongdaGateway')) {
    throw new RuntimeException('cannot install isolated refund transport');
}

function createFinanceTenantSchema(PDO $pdo): void
{
    IdentityMysqlFixture::install($pdo, [
        dirname(__DIR__, 2) . '/app/modules/peanut/file/database/migrations',
        dirname(__DIR__, 2) . '/app/modules/peanut/settings/database/migrations',
        dirname(__DIR__, 2) . '/app/modules/peanut/integration/database/migrations',
        dirname(__DIR__, 2) . '/app/modules/peanut/member/database/migrations',
        dirname(__DIR__, 2) . '/app/modules/peanut/payment/database/migrations',
        dirname(__DIR__, 2) . '/database/installation',
    ]);
}

function seedFinanceTenantSchema(PDO $pdo): void
{
    $pdo->exec(<<<'SQL'
INSERT INTO pa_tenant (id,code,name,display_name,status,created_at,updated_at) VALUES (101,'alpha','Alpha','Alpha','active',UTC_TIMESTAMP(3),UTC_TIMESTAMP(3)),(202,'beta','Beta','Beta','active',UTC_TIMESTAMP(3),UTC_TIMESTAMP(3));
INSERT INTO pa_account (id,display_name,status,created_at,updated_at) VALUES
 (10501,'Alpha operator','active',UTC_TIMESTAMP(3),UTC_TIMESTAMP(3)),
 (10502,'Beta operator','active',UTC_TIMESTAMP(3),UTC_TIMESTAMP(3));
INSERT INTO pa_tenant_member (id,tenant_id,account_id,status,created_at,updated_at) VALUES
 (501,101,10501,'active',UTC_TIMESTAMP(3),UTC_TIMESTAMP(3)),
 (502,202,10502,'active',UTC_TIMESTAMP(3),UTC_TIMESTAMP(3));
INSERT INTO pa_member (id,tenant_id,sn,account,nickname,user_money,total_recharge_amount)
VALUES (11,101,'M-ALPHA','alpha','Alpha',100.00,30.00),(22,202,'M-BETA','beta','Beta',20.00,0);
INSERT INTO pa_recharge_order
  (id,tenant_id,sn,user_id,pay_sn,pay_way,pay_status,pay_time,order_amount,order_terminal,transaction_id,refund_status,create_time)
VALUES (21,101,'RC-ALPHA',11,'PY-ALPHA',2,1,1700000000,10.00,3,'TX-ALPHA',1,1700000000),
       (23,101,'RC-UNKNOWN',11,'PY-UNKNOWN',2,1,1700000000,10.00,3,'TX-UNKNOWN',0,1700000000),
       (24,101,'RC-RETRY',11,'PY-RETRY',2,1,1700000000,10.00,3,'TX-RETRY',0,1700000000),
       (25,101,'RC-FINALIZE',11,'PY-FINALIZE',2,1,1700000000,10.00,3,'TX-FINALIZE',0,1700000000);
INSERT INTO pa_refund_record
  (id,tenant_id,sn,user_id,order_id,order_sn,order_type,order_amount,refund_amount,transaction_id,refund_way,refund_type,refund_status,refund_msg)
VALUES (31,101,'RF-ALPHA',11,21,'RC-ALPHA','recharge',10.00,10.00,'TX-ALPHA',1,1,2,'');
INSERT INTO pa_refund_log
  (id,tenant_id,sn,record_id,user_id,handle_id,order_amount,refund_amount,refund_status,refund_msg)
VALUES (41,101,'RL-ALPHA',31,11,1,10.00,10.00,2,'');
INSERT INTO pa_external_channel_binding (id,tenant_id,provider,callback_key,identity_hash,identity_hint,config_json,status)
VALUES (101,101,'payment.wechat',SHA2('alpha-callback',256),SHA2('alpha-app:alpha-merchant',256),'alpha',JSON_OBJECT('wx_pay_appid','alpha-app','wx_pay_mch_id','alpha-merchant'),1);
INSERT INTO pa_payment_tenant_channel_grant (id,tenant_id,provider,external_binding_id,status) VALUES (101,101,'payment.wechat',101,1);
INSERT INTO pa_recharge_payment_attempt (tenant_id,order_id,pay_way,binding_id,grant_id,merchant_identity_hash,merchant_order_sn,amount_cents,currency,status,transaction_id)
SELECT tenant_id,id,pay_way,101,101,SHA2('alpha-app:alpha-merchant',256),sn,CAST(order_amount*100 AS UNSIGNED),'CNY','paid',transaction_id FROM pa_recharge_order;
SQL);
}

$host = IsolatedBackendEnvironment::required('DB_HOST');
$port = (int) IsolatedBackendEnvironment::required('DB_PORT');
$user = IsolatedBackendEnvironment::required('DB_USER');
$password = IsolatedBackendEnvironment::required('DB_PASS');
$database = RegisteredMysqlTestResource::configuredDatabaseName();
[$pdo, $createdDatabase] = RegisteredMysqlTestResource::openEmptyDatabase($database);

try {
    IsolatedBackendEnvironment::activateDatabase($host, $port, $database, $user, $password, 'multi-tenant');
    $fixtureApp = new think\App(dirname(__DIR__, 2));
    $fixtureApp->initialize();
    set_exception_handler(static function (Throwable $error): void {
        fwrite(STDERR, get_class($error) . ': ' . $error->getMessage() . "\n");
        exit(1);
    });
    ThinkPhpTestConnection::fromPdo($pdo);
    createFinanceTenantSchema($pdo);
    seedFinanceTenantSchema($pdo);
    IsolatedBackendEnvironment::activateDatabase($host, $port, $database, $user, $password, 'multi-tenant');
    $app = new think\App();
    $app->initialize();
    set_exception_handler(static function (Throwable $error): void {
        fwrite(STDERR, get_class($error) . ': ' . $error->getMessage() . "\n");
        exit(1);
    });
    ThinkPhpTestConnection::fromPdo($pdo);
    $alpha = financeTenantContext(101, 501, 'mt03-finance-alpha');
    $beta = financeTenantContext(202, 502, 'mt03-finance-beta');
    financeRun($alpha, 'test.refund.seed.alpha', static function (): void {
        expectFinanceTenant(!RefundRecord::where([])->where('id', 31)->findOrEmpty()->isEmpty(), 'Alpha refund record disappeared');
        expectFinanceTenant(!RefundLog::where([])->where('id', 41)->findOrEmpty()->isEmpty(), 'Alpha refund log disappeared');
    });
    financeRun($beta, 'test.refund.seed.beta', static function (): void {
        expectFinanceTenant(RefundRecord::where([])->where('id', 31)->findOrEmpty()->isEmpty(), 'Beta read Alpha refund record');
        expectFinanceTenant(RefundLog::where([])->where('id', 41)->findOrEmpty()->isEmpty(), 'Beta read Alpha refund log');
    });

    try {
        financeRun($beta, 'test.refund.reject-forged-owner', static fn() => RechargeOrder::create([
            'tenant_id' => 101, 'sn' => 'RC-FORGED-OWNER', 'user_id' => 22, 'order_amount' => '5.00',
        ]));
        throw new RuntimeException('Beta created an order with a forged Alpha owner');
    } catch (DomainException $exception) {
        expectFinanceTenant($exception->getMessage() === 'TENANT_WRITE_OWNERSHIP_MISMATCH', 'forged order owner denial changed');
    }
    expectFinanceTenant(
        (int) $pdo->query("SELECT COUNT(*) FROM pa_recharge_order WHERE sn = 'RC-FORGED-OWNER'")->fetchColumn() === 0,
        'forged order owner denial left an order row',
    );
    $betaOrder = financeRun($beta, 'test.refund.beta-order', static fn() => RechargeOrder::create([
        'sn' => 'RC-BETA-22', 'user_id' => 22, 'pay_sn' => null, 'pay_way' => 2,
        'pay_status' => RechargeOrder::PAY_STATUS_UNPAID, 'order_amount' => '5.00',
        'order_terminal' => 3, 'transaction_id' => null, 'refund_status' => 0,
    ]));
    expectFinanceTenant((int) $betaOrder->tenant_id === 202, 'payload forged order Tenant ownership');
    financeRun($alpha, 'test.refund.beta-order-isolation', static function () use ($betaOrder): void {
        expectFinanceTenant(RechargeOrder::where([])->where('id', (int) $betaOrder->id)->findOrEmpty()->isEmpty(), 'Alpha read Beta order');
    });

    $idempotency = $app->make(IdempotentCommandExecutor::class);
    $xlsx = (new ReflectionClass(XlsxExportService::class))->newInstanceWithoutConstructor();
    $refunds = financeRefundService($xlsx, $idempotency);

    RefundBehaviorGateway::mode('RC-UNKNOWN', 'accepted_unknown');
    $unknown = financeRun($alpha, 'test.refund.accepted-unknown', static fn() => $refunds->refund(
        $alpha,
        ['recharge_id' => 23],
        1,
        'refund-unknown-001',
    ));
    expectFinanceTenant($unknown === '操作成功', 'accepted Provider result was not kept pending');
    $unknownRecord = financeRun($alpha, 'test.refund.accepted-unknown-record', static fn() => RefundRecord::where([])
        ->where('order_id', 23)->findOrEmpty());
    expectFinanceTenant(!$unknownRecord->isEmpty() && (int) $unknownRecord->refund_status === RefundEnum::REFUND_ING, 'accepted unknown result did not stay ING');
    expectFinanceTenant(
        (string) $pdo->query("SELECT status FROM pa_tenant_idempotency_record WHERE operation_key='recharge.refund.create' ORDER BY id DESC LIMIT 1")->fetchColumn() === 'completed',
        'pending business result and idempotency receipt did not commit together',
    );
    $unknownReplay = financeRun($alpha, 'test.refund.accepted-unknown-replay', static fn() => $refunds->refund(
        $alpha,
        ['recharge_id' => 23],
        1,
        'refund-unknown-001',
    ));
    expectFinanceTenant($unknownReplay === $unknown, 'duplicate refund request did not replay its receipt');
    expectFinanceTenant(
        (RefundBehaviorGateway::$refundKeys['RC-UNKNOWN'] ?? []) === [(string) $unknownRecord->sn]
            && (RefundBehaviorGateway::$effects[(string) $unknownRecord->sn] ?? 0) === 1,
        'accepted unknown refund repeated the external effect or changed its Provider key',
    );
    financeReconcile($alpha);
    $unknownRecord = financeRun($alpha, 'test.refund.accepted-unknown-settled', static fn() => RefundRecord::where([])
        ->where('id', (int) $unknownRecord->id)->findOrEmpty());
    expectFinanceTenant((int) $unknownRecord->refund_status === RefundEnum::REFUND_SUCCESS, 'reconcile did not settle accepted refund');
    expectFinanceTenant(
        (RefundBehaviorGateway::$queryKeys['RC-UNKNOWN'] ?? []) === [(string) $unknownRecord->sn],
        'reconcile did not query with the original RefundRecord SN',
    );

    RefundBehaviorGateway::mode('RC-RETRY', 'known_failure_then_success');
    try {
        financeRun($alpha, 'test.refund.known-failure', static fn() => $refunds->refund(
            $alpha,
            ['recharge_id' => 24],
            1,
            'refund-retry-001',
        ));
        throw new LogicException('known Provider failure unexpectedly succeeded');
    } catch (RuntimeException $exception) {
        expectFinanceTenant($exception->getMessage() === 'provider rejected refund', 'known Provider failure result changed');
    }
    $retryRecord = financeRun($alpha, 'test.refund.known-failure-record', static fn() => RefundRecord::where([])
        ->where('order_id', 24)->findOrEmpty());
    expectFinanceTenant((int) $retryRecord->refund_status === RefundEnum::REFUND_ERROR, 'known Provider failure did not settle ERROR');
    try {
        financeRun($alpha, 'test.refund.known-failure-replay', static fn() => $refunds->refund(
            $alpha,
            ['recharge_id' => 24],
            1,
            'refund-retry-001',
        ));
        throw new LogicException('failed refund unexpectedly replayed as success');
    } catch (BusinessException $exception) {
        expectFinanceTenant(
            $exception->errorCode === 'REFUND_REPLAY_REJECTED' && $exception->getMessage() === 'provider rejected refund',
            'failed refund receipt was not replayed',
        );
    }
    $retried = financeRun($alpha, 'test.refund.retry', static fn() => $refunds->refundAgain(
        $alpha,
        ['record_id' => (int) $retryRecord->id],
        1,
    ));
    expectFinanceTenant($retried === '操作成功', 'failed refund retry did not succeed');
    expectFinanceTenant(
        (RefundBehaviorGateway::$refundKeys['RC-RETRY'] ?? []) === [(string) $retryRecord->sn, (string) $retryRecord->sn]
            && (RefundBehaviorGateway::$effects[(string) $retryRecord->sn] ?? 0) === 1,
        'refund retry changed the Provider key or duplicated the external effect',
    );
    expectFinanceTenant(
        financeRun($alpha, 'test.refund.retry-logs', static fn() => (int) RefundLog::where([])
            ->where('record_id', (int) $retryRecord->id)->count()) === 2,
        'refund retry did not append exactly one local attempt log',
    );

    RefundBehaviorGateway::mode('RC-FINALIZE', 'success');
    $failingRefunds = financeRefundService(
        $xlsx,
        new FailingRefundIdempotency($idempotency),
    );
    try {
        financeRun($alpha, 'test.refund.finalize-failure', static fn() => $failingRefunds->refund(
            $alpha,
            ['recharge_id' => 25],
            1,
            'refund-finalize-001',
        ));
        throw new LogicException('fixture receipt finalize unexpectedly succeeded');
    } catch (RuntimeException $exception) {
        expectFinanceTenant($exception->getMessage() === 'fixture receipt finalize failure', 'fixture receipt finalize failure was hidden');
    }
    $finalizeRecord = financeRun($alpha, 'test.refund.finalize-failure-record', static fn() => RefundRecord::where([])
        ->where('order_id', 25)->findOrEmpty());
    $finalizeLog = financeRun($alpha, 'test.refund.finalize-failure-log', static fn() => RefundLog::where([])
        ->where('record_id', (int) $finalizeRecord->id)->order('id', 'desc')->findOrEmpty());
    $finalizeOrder = financeRun($alpha, 'test.refund.finalize-failure-order', static fn() => RechargeOrder::where([])
        ->where('id', 25)->findOrEmpty());
    expectFinanceTenant(
        (int) $finalizeRecord->refund_status === RefundEnum::REFUND_ING
            && (int) $finalizeLog->refund_status === RefundEnum::REFUND_ING
            && trim((string) $finalizeOrder->refund_transaction_id) === ''
            && (string) $pdo->query("SELECT status FROM pa_tenant_idempotency_record WHERE operation_key='recharge.refund.create' ORDER BY id DESC LIMIT 1")->fetchColumn() === 'processing',
        'business result committed without its idempotency receipt',
    );
    expectFinanceTenant(
        (RefundBehaviorGateway::$refundKeys['RC-FINALIZE'] ?? []) === [(string) $finalizeRecord->sn]
            && (RefundBehaviorGateway::$effects[(string) $finalizeRecord->sn] ?? 0) === 1,
        'finalize failure changed the Provider key or repeated the external effect',
    );
    financeReconcile($alpha);
    $finalizeRecord = financeRun($alpha, 'test.refund.finalize-failure-settled', static fn() => RefundRecord::where([])
        ->where('id', (int) $finalizeRecord->id)->findOrEmpty());
    expectFinanceTenant(
        (int) $finalizeRecord->refund_status === RefundEnum::REFUND_SUCCESS
            && (RefundBehaviorGateway::$queryKeys['RC-FINALIZE'] ?? []) === [(string) $finalizeRecord->sn],
        'reconcile did not recover the rolled-back local Provider result with the stable key',
    );

    $alphaRecordIds = [(int) $unknownRecord->id, (int) $retryRecord->id, (int) $finalizeRecord->id];
    expectFinanceTenant(
        financeRun($beta, 'test.refund.behavior-isolation', static fn() => (int) RefundRecord::where([])
            ->whereIn('id', $alphaRecordIds)->count()) === 0,
        'Beta read Alpha refund behavior records',
    );
} finally {
    RegisteredMysqlTestResource::cleanup($pdo, $database, $createdDatabase);
}

echo "MT03-RECHARGE-REFUND-TENANT-001 passed\n";
