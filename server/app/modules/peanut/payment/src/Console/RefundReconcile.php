<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Payment\Console;

use PeanutAdmin\Modules\Payment\Contract\RefundReconciliationCommands;
use PeanutAdmin\Kernel\Tenancy\ScheduledTenantContext;
use PeanutAdmin\Modules\Payment\Infrastructure\Channel\PaymentTenantDiagnostics;
use app\common\execution\ContextualCommand;
use app\common\execution\CurrentExecutionContext;
use app\common\execution\ExecutionContextStore;
use think\console\Input;
use think\console\Output;

/** 查询支付渠道并收敛充值退款的最终状态。 */
class RefundReconcile extends ContextualCommand
{
    public function __construct(
        ?ExecutionContextStore $contexts = null,
        ?CurrentExecutionContext $executionContext = null,
        private readonly ?RefundReconciliationCommands $refunds = null,
    ) {
        parent::__construct($contexts, $executionContext);
    }

    protected function configure()
    {
        $this->setName('refund:reconcile')->setDescription('收敛充值退款状态');
    }

    protected function handle(Input $input, Output $output): int
    {
        $scope = ScheduledTenantContext::require();
        $diagnostics = [
            'scope' => 'tenant',
            'tenant_id' => $scope->tenantId(),
            'correlation_id' => $scope->contextIdentity(),
        ];
        $result = $this->refunds()->reconcile($scope, $diagnostics);

        $output->writeln(sprintf(
            '[refund:reconcile] checked=%d settled=%d',
            $result['checked'],
            $result['settled'],
        ));
        return 0;
    }

    private function refunds(): RefundReconciliationCommands
    {
        return $this->refunds
            ?? throw new \LogicException('COMMAND_DEPENDENCIES_NOT_INJECTED');
    }

}
