<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Collaboration\Model;

use app\common\model\TenantOwnedModel;

final class CollaborationConnection extends TenantOwnedModel
{
    protected $name = 'collaboration_connection';
    protected $autoWriteTimestamp = false;
}
