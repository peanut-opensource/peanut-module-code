<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Identity\Persistence\Model;

use think\Model;

final class Tenant extends Model
{
    /** @var string */ protected $name = 'tenant';
    /** @var string */ protected $pk = 'id';
    /** @var bool */ protected $autoWriteTimestamp = false;

    /** @var array<string, string> */
    protected $type = [
        'id' => 'integer',
        'revision' => 'integer',
        'authorization_revision' => 'integer',
    ];

    /** @var array<string, string> Keep aligned with the Identity migrations. */
    protected $schema = [
        'id' => 'integer',
        'code' => 'string',
        'name' => 'string',
        'display_name' => 'string',
        'status' => 'string',
        'locale' => 'string',
        'timezone' => 'string',
        'security_revision' => 'bigint',
        'authorization_revision' => 'integer',
        'revision' => 'integer',
        'activated_at' => 'datetime',
        'suspended_at' => 'datetime',
        'closed_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}
