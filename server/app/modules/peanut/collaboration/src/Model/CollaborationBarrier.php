<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Collaboration\Model;

use app\common\model\TenantOwnedModel;

final class CollaborationBarrier extends TenantOwnedModel
{
    protected $name = 'collaboration_barrier';
    protected $autoWriteTimestamp = false;
}
