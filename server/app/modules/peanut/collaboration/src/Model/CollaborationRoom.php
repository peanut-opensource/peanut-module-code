<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Collaboration\Model;

use app\common\model\TenantOwnedModel;

final class CollaborationRoom extends TenantOwnedModel
{
    protected $name = 'collaboration_room';
    protected $autoWriteTimestamp = false;
}
