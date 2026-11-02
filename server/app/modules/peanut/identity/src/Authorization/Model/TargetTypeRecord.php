<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Identity\Authorization\Model;

use think\Model;

final class TargetTypeRecord extends Model
{
    /** @var string */ protected $name = 'target_type';
    /** @var string */ protected $pk = 'id';
    /** @var bool */ protected $autoWriteTimestamp = false;
    /** @var array<string, string> */ protected $type = ['id' => 'integer'];

    /** @var array<string, string> Keep aligned with the Identity migrations. */
    protected $schema = [
        'id' => 'integer',
        'key' => 'string',
        'module_key' => 'string',
        'name' => 'string',
        'resolver_key' => 'string',
        'catalog_provider_key' => 'string',
        'id_format' => 'string',
        'status' => 'string',
        'manifest_version' => 'string',
        'manifest_digest' => 'string',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}
