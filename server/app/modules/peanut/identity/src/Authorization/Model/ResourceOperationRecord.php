<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Identity\Authorization\Model;

use think\Model;

final class ResourceOperationRecord extends Model
{
    /** @var string */ protected $name = 'resource_operation';
    /** @var string */ protected $pk = 'id';
    /** @var bool */ protected $autoWriteTimestamp = false;
    /** @var array<string, string> */ protected $type = [
        'id' => 'integer',
        'protected_resource_id' => 'integer',
    ];

    /** @var array<string, string> Keep aligned with the Identity migrations. */
    protected $schema = [
        'id' => 'integer',
        'protected_resource_id' => 'integer',
        'operation' => 'string',
        'access_mode' => 'string',
        'target_cardinality' => 'string',
        'permission_match' => 'string',
        'audit_level' => 'string',
        'status' => 'string',
        'manifest_digest' => 'string',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}
