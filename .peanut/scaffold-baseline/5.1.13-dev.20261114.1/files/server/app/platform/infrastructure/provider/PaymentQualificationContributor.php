<?php

declare(strict_types=1);

namespace app\platform\infrastructure\provider;

final class PaymentQualificationContributor extends AbstractTenantBindingQualificationContributor
{
    protected function definitions(): array
    {
        return [
            ['provider_key' => 'payment.wechat', 'binding_provider' => 'payment.wechat',
                'category' => 'payment', 'callback_required' => true],
            ['provider_key' => 'payment.alipay', 'binding_provider' => 'payment.alipay',
                'category' => 'payment', 'callback_required' => true],
        ];
    }
}
