<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Payment\Contract;

use app\common\http\PageResult;

interface RechargeQueries
{
    public function config(object $context, int $memberId, int $terminal): array;

    public function detail(object $context, int $memberId, int $orderId): array;

    public function lists(object $context, int $memberId, array $params): PageResult;
}
