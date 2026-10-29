<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\OAuth\Model;

use app\common\model\TenantOwnedModel;
use think\model\concern\SoftDelete;

class OfficialAccountReply extends TenantOwnedModel
{
    use SoftDelete;

    protected $name = 'official_account_reply';
    protected $deleteTime = 'delete_time';
    protected $defaultSoftDelete = 0;
}
