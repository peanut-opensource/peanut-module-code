<?php

declare(strict_types=1);

use PeanutAdmin\Modules\Member\Service\MemberBalanceService;
use app\common\value\Money;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

function expectMemberFinance(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$serverRoot = dirname(__DIR__, 2);
$repositoryRoot = dirname($serverRoot);
$balanceServicePath = $serverRoot . '/app/modules/peanut/member/src/Service/MemberBalanceService.php';
$balanceService = (string) file_get_contents($balanceServicePath);
$balanceContractPath = $serverRoot . '/app/modules/peanut/member/src/Service/MemberBalanceContractService.php';
$balanceContract = (string) file_get_contents($balanceContractPath);
$memberManifest = (string) file_get_contents($serverRoot . '/app/modules/peanut/member/module.json');
$memberProvider = (string) file_get_contents($serverRoot . '/app/modules/peanut/member/src/ModuleProvider.php');
$administration = (string) file_get_contents($serverRoot . '/app/modules/peanut/member/src/Service/MemberAdministrationService.php');
$schema = (string) file_get_contents($serverRoot . '/app/modules/peanut/member/database/migrations/20261102000003_initial_member_schema.php');

expectMemberFinance(Money::toCents('10.10') === 1010, 'decimal amount conversion changed');
expectMemberFinance(Money::toCents(0.1) === 10, 'float amount conversion changed');
expectMemberFinance(Money::fromCents(1010) === '10.10', 'money formatting changed');
expectMemberFinance(
    str_contains($balanceService, 'Member::where([])->lock(true)'),
    'balance owner must lock the Tenant-scoped member row',
);
expectMemberFinance(str_contains($balanceService, 'appendBalanceLog'), 'balance owner must append a ledger row');
expectMemberFinance(!str_contains($balanceService, '$member->balance'), 'balance owner must not write a compatibility mirror');
expectMemberFinance(!str_contains($balanceService, "'after_amount' =>"), 'ledger owner must not write a compatibility mirror');
expectMemberFinance(!str_contains($schema, '`balance` DECIMAL'), 'member compatibility balance remains in the fresh schema');
expectMemberFinance(!str_contains($schema, '`after_amount` DECIMAL'), 'ledger compatibility amount remains in the fresh schema');
$memberSave = strpos($balanceService, '$member->save()');
$ledgerAppend = strpos($balanceService, 'appendBalanceLog');
expectMemberFinance(
    $memberSave !== false && $ledgerAppend !== false && $memberSave < $ledgerAppend,
    'ledger must record the updated balance',
);

$directWriters = [];
$balanceCallers = [];
$ledgerWriters = [];
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($serverRoot . '/app', FilesystemIterator::SKIP_DOTS),
);
foreach ($iterator as $file) {
    if (!$file->isFile() || $file->getExtension() !== 'php'
        || str_contains($file->getPathname(), '/api/metadata/')) {
        continue;
    }
    $source = (string) file_get_contents($file->getPathname());
    if (preg_match('/->user_money\s*=|[\'\"]user_money[\'\"]\s*=>/', $source) === 1) {
        $directWriters[] = $file->getPathname();
    }
    if (str_contains($source, 'MemberBalanceService::applyInTransaction')) {
        $balanceCallers[] = $file->getPathname();
    }
    if (str_contains($source, 'MemberBalanceLog::create')) {
        $ledgerWriters[] = $file->getPathname();
    }
}
expectMemberFinance(
    $directWriters === [$balanceServicePath],
    'user_money must have exactly one application writer: ' . implode(', ', $directWriters),
);

$callers = [
    'app/modules/peanut/payment/src/Service/RechargeApplicationService.php',
    'app/modules/peanut/payment/src/Service/RechargeAdministrationService.php',
];
expectMemberFinance(
    $balanceCallers === [$balanceContractPath],
    'Member balance contract must be the only caller of the unique writer: ' . implode(', ', $balanceCallers),
);
expectMemberFinance(str_contains($administration, 'MemberBalanceCommands'), 'member administration must depend on the balance contract');
expectMemberFinance(
    str_contains($memberManifest, 'Contract\\\\MemberQueries')
        && str_contains($memberManifest, 'Contract\\\\MemberBalanceCommands'),
    'peanut.member must export the query and balance command contracts',
);
expectMemberFinance(
    str_contains($memberProvider, 'MemberIdentityCommands::class =>'),
    'peanut.member must bind its identity command contract at startup',
);
expectMemberFinance(
    str_contains($balanceContract, 'MemberBalanceService::applyInTransaction'),
    'Member balance command must delegate to the unique writer',
);
expectMemberFinance(
    $ledgerWriters === [$balanceServicePath],
    'member balance ledger must have exactly one writer',
);
foreach ($callers as $relativePath) {
    $source = (string) file_get_contents($serverRoot . '/' . $relativePath);
    $call = strpos($source, 'applyInTransaction(');
    expectMemberFinance($call !== false, 'balance path bypasses the unique owner: ' . $relativePath);
    $beforeCall = substr($source, 0, $call);
    $hasTransactionBoundary = strrpos($beforeCall, 'Db::transaction(') !== false;
    expectMemberFinance($hasTransactionBoundary, 'balance path lacks an outer transaction: ' . $relativePath);
    expectMemberFinance(!str_contains($source, 'MemberBalanceLog::create'), 'caller writes the ledger directly: ' . $relativePath);
}

$settle = (string) file_get_contents($serverRoot . '/app/modules/peanut/payment/src/Service/RechargeApplicationService.php');
$paidGuard = strpos($settle, 'pay_status === RechargeOrder::PAY_STATUS_PAID');
$credit = strpos($settle, 'memberBalances->applyInTransaction');
expectMemberFinance($paidGuard !== false && $credit !== false && $paidGuard < $credit, 'paid callback guard must precede credit');
expectMemberFinance(strpos($settle, "where('sn', \$orderSn)->lock(true)") < $paidGuard, 'recharge order must be locked before the paid guard');
expectMemberFinance(
    !str_contains($settle, 'Member::where('),
    'Payment must query Member through the public contract',
);

$refund = (string) file_get_contents($serverRoot . '/app/modules/peanut/payment/src/Service/RechargeAdministrationService.php');
$retryStart = strpos($refund, 'public function refundAgain');
$retryEnd = strpos($refund, 'private static function assertRefundableOrder', $retryStart ?: 0);
expectMemberFinance($retryStart !== false && $retryEnd !== false, 'refund retry boundary is missing');
expectMemberFinance(
    str_contains($refund, 'private readonly PaymentRetryLock $retryLocks')
        && !str_contains(substr($refund, $retryStart, $retryEnd - $retryStart), 'applyInTransaction('),
    'refund retry must not deduct the balance again',
);

$paymentSchema = (string) file_get_contents($serverRoot . '/app/modules/peanut/payment/database/migrations/20261102000005_initial_payment_schema.php');
expectMemberFinance(str_contains($paymentSchema, 'uk_transaction_id'), 'transaction id unique guard is missing');
expectMemberFinance(
    str_contains($paymentSchema, 'idx_refund_record_tenant_order_amount')
        && !str_contains($paymentSchema, 'uk_refund_record_tenant_order')
        && !str_contains($paymentSchema, 'uk_refund_record_order_global'),
    'partial-refund cumulative lookup schema is missing',
);

// Historical browser receipts are not current module execution evidence.

expectMemberFinance(
    preg_match('/PeanutAdmin\\\\(?!Modules\\\\)[^;]*(?:Balance|Ledger|FinanceService)/', $balanceService) !== 1,
    'application balance owner must not delegate balance or ledger behavior to Core',
);

echo "PB05-MEMBER-FINANCE-001 passed\n";
