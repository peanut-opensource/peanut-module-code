<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Payment\Infrastructure\Channel;

use Yansongda\Pay\Plugin\Alipay\V2\StartPlugin;

/** Peanut's existing Alipay bindings use public-key mode, without certificate serials. */
final class AlipayPublicKeyStart extends StartPlugin
{
    protected function getAppCertSn(string $tenant, array $config): string
    {
        return '';
    }

    protected function getAlipayRootCertSn(string $tenant, array $config): string
    {
        return '';
    }
}
