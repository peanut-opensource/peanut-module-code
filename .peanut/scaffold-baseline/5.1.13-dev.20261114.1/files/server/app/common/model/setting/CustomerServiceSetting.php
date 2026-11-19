<?php

declare(strict_types=1);

namespace app\common\model\setting;

use app\common\model\TenantOwnedModel;

final class CustomerServiceSetting extends TenantOwnedModel
{
    protected $name = 'customer_service_setting';
}
