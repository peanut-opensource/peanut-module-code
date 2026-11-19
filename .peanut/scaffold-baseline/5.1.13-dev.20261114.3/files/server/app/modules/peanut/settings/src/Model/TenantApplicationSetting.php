<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Settings\Model;

use PeanutAdmin\Kernel\Persistence\Model\TenantModel;

final class TenantApplicationSetting extends TenantModel
{
    protected $name = 'tenant_setting';
    protected $pk = 'id';

    /** @var array<string,string> Defined by the initial Settings migration. */
    protected $schema = [
        'id' => 'bigint',
        'tenant_id' => 'bigint',
        'namespace' => 'string',
        'config_json' => 'json',
        'revision' => 'bigint',
        'create_time' => 'int',
        'update_time' => 'int',
    ];
}
