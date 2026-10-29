<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Payment\Contract;

interface RefundReconciliationCommands
{
    /** @param array<string,mixed> $diagnostics @return array{checked:int,settled:int} */
    public function reconcile(object $scope, array $diagnostics): array;
}
