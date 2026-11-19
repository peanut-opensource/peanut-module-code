<?php

declare(strict_types=1);

namespace app\common\model\auth;

use app\common\model\SharedModel;

class SystemMenu extends SharedModel
{
    protected $name = 'system_menu';
    protected $autoWriteTimestamp = false;
}
