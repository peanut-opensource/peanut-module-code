<?php

declare(strict_types=1);

namespace app\common\model;

use think\Model;

class BaseModel extends Model
{
    protected $autoWriteTimestamp = 'int';
    protected $createTime = 'create_time';
    protected $updateTime = 'update_time';
    protected function validateAndFilterData(bool $isUpdate): array
    {
        $result = parent::validateAndFilterData($isUpdate);
        if (!$isUpdate && $result[0] !== []) {
            // Explicit schema skips discovery; load it before INSERT so SHOW cannot reset the PDO insert ID.
            $this->getQuery()->getAutoInc();
        }
        return $result;
    }
}
