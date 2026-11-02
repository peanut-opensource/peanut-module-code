<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Identity\Authorization\Model;

use think\Model;

final class ResourceOperationTargetTypeRecord extends Model
{
    /** @var string */ protected $name = 'resource_operation_target_type';
    /** @var string */ protected $pk = 'id';
    /** @var bool */ protected $autoWriteTimestamp = false;
    /** @var array<string, string> */ protected $type = ['id' => 'integer'];

    /** @var array<string, string> Keep aligned with the Identity migrations. */
    protected $schema = [
        'id' => 'integer',
        'resource_operation_id' => 'bigint',
        'target_type_id' => 'bigint',
        'target_role' => 'string',
        'input_mode' => 'string',
        'policy_selection_permission_id' => 'bigint',
        'status' => 'string',
    ];
}
