<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Identity\Persistence\Model;

final class Role extends \PeanutAdmin\Kernel\Persistence\Model\TenantModel
{
    /** @var string */ protected $name = 'role';
    /** @var array<string, string> */ protected $type = [
        'id' => 'integer',
        'tenant_id' => 'integer',
        'is_builtin' => 'boolean',
        'authorization_revision' => 'integer',
    ];
}
