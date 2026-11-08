<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Identity\Persistence\Model;

final class Department extends \PeanutAdmin\Kernel\Persistence\Model\TenantModel
{
    /** @var string */ protected $name = 'department';
    /** @var string */ protected $pk = 'id';
    /** @var array<string, string> */ protected $type = [
        'id' => 'integer',
        'tenant_id' => 'integer',
        'parent_id' => 'integer',
        'revision' => 'integer',
    ];

    /** @var array<string, string> Keep aligned with the module migrations and type overrides. */
    protected $schema = [
        'id' => 'integer',
        'tenant_id' => 'integer',
        'parent_id' => 'integer',
        'code' => 'string',
        'name' => 'string',
        'leader' => 'string',
        'leader_member_id' => 'bigint',
        'mobile' => 'string',
        'sort_order' => 'int',
        'status' => 'string',
        'revision' => 'integer',
        'archived_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}
