<?php

declare(strict_types=1);

namespace app\common\model\decoration;

use app\common\model\TenantOwnedModel;

class DecorateTabbar extends TenantOwnedModel
{
    protected $name = 'decorate_tabbar';
}
