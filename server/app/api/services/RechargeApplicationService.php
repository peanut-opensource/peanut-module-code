<?php

declare(strict_types=1);

namespace app\api\services;

use app\common\http\PageResult;
use PeanutAdmin\Modules\Payment\Contract\RechargeCommands;
use PeanutAdmin\Modules\Payment\Contract\RechargeQueries;

/** Member API facade for the Payment Module's recharge use cases. */
final readonly class RechargeApplicationService
{
    public function __construct(
        private RechargeCommands $commands,
        private RechargeQueries $queries,
    ) {}

    public function config(object $context, int $memberId, int $terminal): array
    {
        return $this->queries->config($context, $memberId, $terminal);
    }

    public function create(object $context, int $memberId, array $params): array
    {
        return $this->commands->create($context, $memberId, $params);
    }

    public function prepay(
        object $context,
        int $memberId,
        int $orderId,
        int $payWay,
        string $notifyUrl,
        string $clientIp = '',
        string $openid = '',
    ): array {
        return $this->commands->prepay(
            $context,
            $memberId,
            $orderId,
            $payWay,
            $notifyUrl,
            $clientIp,
            $openid,
        );
    }

    public function detail(object $context, int $memberId, int $orderId): array
    {
        return $this->queries->detail($context, $memberId, $orderId);
    }

    public function lists(object $context, int $memberId, array $params): PageResult
    {
        return $this->queries->lists($context, $memberId, $params);
    }

}
