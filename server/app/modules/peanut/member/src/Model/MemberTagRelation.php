<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Member\Model;

use app\common\model\TenantOwnedModel;

class MemberTagRelation extends TenantOwnedModel
{
    protected $name = 'member_tag_relation';
    // 无软删除、无自动时间戳
    protected $autoWriteTimestamp = false;
}
