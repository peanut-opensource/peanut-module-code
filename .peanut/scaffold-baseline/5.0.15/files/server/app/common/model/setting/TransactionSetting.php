<?php

declare(strict_types=1);

namespace app\common\model\setting;

use app\common\model\TenantOwnedModel;

final class TransactionSetting extends TenantOwnedModel
{
    protected $name = 'transaction_setting';
}
