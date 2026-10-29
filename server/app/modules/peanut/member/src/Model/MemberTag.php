<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Member\Model;

use app\common\model\TenantOwnedModel;
use think\model\concern\SoftDelete;

class MemberTag extends TenantOwnedModel
{
    use SoftDelete;
    protected $name       = 'member_tag';
    protected $deleteTime = 'delete_time';
}
