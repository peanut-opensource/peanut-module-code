<?php

declare(strict_types=1);

namespace app\common\model;

use think\Model;
use think\db\Query;

/**
 * ThinkORM dispatches these calls through a query bound to the concrete model.
 *
 * @method static Query<static> where(mixed $field, mixed $op = null, mixed $condition = null)
 * @method static Query<static> whereIn(string $field, mixed $condition)
 * @method static Query<static> field(string|array|\think\db\Raw|bool $field)
 * @method static Query<static> order(string|array|\think\db\Raw $field, string $order = '')
 * @method static static|null find(mixed $data = null)
 * @method static static findOrEmpty(mixed $data = null)
 * @method static static findOrFail(mixed $data = null)
 */
class BaseModel extends Model
{
    protected $autoWriteTimestamp = 'int';
    protected $createTime = 'create_time';
    protected $updateTime = 'update_time';
    /** @return static */
    final public static function createModel(array $data): static
    {
        $model = static::create($data);
        if (!$model instanceof static) {
            throw new \LogicException('MODEL_ENTITY_MAPPING_UNEXPECTED');
        }
        return $model;
    }

    /** @return Query<static> */
    public function getQuery(): Query
    {
        return parent::getQuery();
    }

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
