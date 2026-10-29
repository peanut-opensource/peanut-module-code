<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Collaboration\Model;

use app\common\model\TenantOwnedModel;

final class CollaborationAdmission extends TenantOwnedModel
{
    protected $name = 'collaboration_admission';
    protected $autoWriteTimestamp = false;
}
