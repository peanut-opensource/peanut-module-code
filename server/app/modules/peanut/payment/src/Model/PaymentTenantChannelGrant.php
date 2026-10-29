<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Payment\Model;

use app\common\model\TenantOwnedModel;

final class PaymentTenantChannelGrant extends TenantOwnedModel
{
    protected $name = 'payment_tenant_channel_grant';
}
