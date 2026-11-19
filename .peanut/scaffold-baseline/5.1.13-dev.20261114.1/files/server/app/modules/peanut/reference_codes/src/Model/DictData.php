<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\ReferenceCodes\Model;

use app\common\model\TenantOwnedModel;
use think\model\concern\SoftDelete;

class DictData extends TenantOwnedModel
{
    use SoftDelete;
    protected $name = 'dict_data';
    protected $pk = 'id';
    protected $deleteTime = 'delete_time';

    /** @var array<string, string> Keep aligned with the module migrations and type overrides. */
    protected $schema = [
        'id' => 'int',
        'name' => 'string',
        'value' => 'string',
        'type_id' => 'int',
        'type_value' => 'string',
        'sort' => 'int',
        'is_disable' => 'int',
        'remark' => 'string',
        'create_time' => 'int',
        'update_time' => 'int',
        'delete_time' => 'int',
        'tenant_id' => 'bigint',
    ];
}
