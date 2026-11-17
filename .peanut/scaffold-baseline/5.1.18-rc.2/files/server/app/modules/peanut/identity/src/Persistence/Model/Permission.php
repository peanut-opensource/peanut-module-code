<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Identity\Persistence\Model;

use think\Model;

final class Permission extends Model
{
    /** @var string */ protected $name = 'permission';
    /** @var string */ protected $pk = 'id';
    /** @var bool */ protected $autoWriteTimestamp = false;
    /** @var array<string, string> */ protected $type = ['id' => 'integer'];

    /** @var array<string, string> Keep aligned with the Identity migrations. */
    protected $schema = [
        'id' => 'integer',
        'key' => 'string',
        'module_key' => 'string',
        'type' => 'string',
        'name' => 'string',
        'description' => 'string',
        'risk_level' => 'string',
        'status' => 'string',
        'manifest_version' => 'string',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'retired_at' => 'datetime',
    ];
}
