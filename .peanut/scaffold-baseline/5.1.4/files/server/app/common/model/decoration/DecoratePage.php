<?php

declare(strict_types=1);

namespace app\common\model\decoration;

use app\common\model\TenantOwnedModel;

class DecoratePage extends TenantOwnedModel
{
    protected $name = 'decorate_page';
    protected $type = [
        'tenant_id' => 'integer',
        'type' => 'integer',
    ];
}
