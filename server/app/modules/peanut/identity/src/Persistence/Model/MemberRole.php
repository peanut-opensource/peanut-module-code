<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Identity\Persistence\Model;

final class MemberRole extends \PeanutAdmin\Kernel\Persistence\Model\TenantModel
{
    /** @var string */ protected $name = 'member_role';
    /** @var string */ protected $pk = 'id';
    /** @var array<string, string> */ protected $type = [
        'id' => 'integer',
        'tenant_id' => 'integer',
        'tenant_member_id' => 'integer',
        'role_id' => 'integer',
    ];

    /** @var array<string, string> Keep aligned with the Identity migrations. */
    protected $schema = [
        'id' => 'integer',
        'tenant_id' => 'integer',
        'tenant_member_id' => 'integer',
        'role_id' => 'integer',
        'assigned_by_member_id' => 'bigint',
        'assigned_at' => 'datetime',
    ];
}
