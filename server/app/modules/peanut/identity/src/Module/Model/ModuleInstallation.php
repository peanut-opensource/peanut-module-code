<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Identity\Module\Model;

use think\Model;

final class ModuleInstallation extends Model
{
    /** @var string */ protected $name = 'module_installation';
    /** @var string */ protected $pk = 'id';
    /** @var bool */ protected $autoWriteTimestamp = false;

    /** @var array<string, string> */
    protected $type = [
        'id' => 'integer',
        'manifest_schema_version' => 'integer',
        'revision' => 'integer',
    ];

    /** @var array<string, string> Keep aligned with the Identity migrations. */
    protected $schema = [
        'id' => 'integer',
        'module_key' => 'string',
        'installed_version' => 'string',
        'manifest_schema_version' => 'integer',
        'manifest_digest' => 'string',
        'status' => 'string',
        'revision' => 'integer',
        'installed_at' => 'datetime',
        'activated_at' => 'datetime',
        'upgraded_at' => 'datetime',
        'last_error_code' => 'string',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}
