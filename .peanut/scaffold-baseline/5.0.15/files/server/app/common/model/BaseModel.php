<?php

declare(strict_types=1);

namespace app\common\model;

use think\Model;

class BaseModel extends Model
{
    protected $autoWriteTimestamp = 'int';
    protected $createTime = 'create_time';
    protected $updateTime = 'update_time';
}
