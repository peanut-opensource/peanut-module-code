<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Identity\Authorization\Model;

use think\Model;

final class ProtectedResourceRecord extends Model
{
    /** @var string */ protected $name = 'protected_resource';
    /** @var string */ protected $pk = 'id';
    /** @var bool */ protected $autoWriteTimestamp = false;
    /** @var array<string, string> */ protected $type = ['id' => 'integer'];

    /** @var array<string, string> Keep aligned with the Identity migrations. */
    protected $schema = [
        'id' => 'integer',
        'key' => 'string',
        'module_key' => 'string',
        'name' => 'string',
        'ownership' => 'string',
        'provider_key' => 'string',
        'status' => 'string',
        'manifest_version' => 'string',
        'manifest_digest' => 'string',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'retired_at' => 'datetime',
    ];
}
