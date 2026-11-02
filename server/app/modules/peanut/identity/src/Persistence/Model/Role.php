<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Identity\Persistence\Model;

final class Role extends \PeanutAdmin\Kernel\Persistence\Model\TenantModel
{
    /** @var string */ protected $name = 'role';
    /** @var string */ protected $pk = 'id';
    /** @var array<string, string> Keep aligned with the Identity migrations. */
    protected $schema = [
        'id' => 'integer',
        'tenant_id' => 'integer',
        'key' => 'string',
        'name' => 'string',
        'description' => 'string',
        'is_builtin' => 'boolean',
        'status' => 'string',
        'authorization_revision' => 'integer',
        'archived_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /** @var array<string, string> */ protected $type = [
        'id' => 'integer',
        'tenant_id' => 'integer',
        'is_builtin' => 'boolean',
        'authorization_revision' => 'integer',
    ];
}
