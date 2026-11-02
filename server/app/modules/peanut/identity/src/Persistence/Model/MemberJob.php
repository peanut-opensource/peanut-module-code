<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Identity\Persistence\Model;

final class MemberJob extends \PeanutAdmin\Kernel\Persistence\Model\TenantModel
{
    /** @var string */ protected $name = 'member_job';
    /** @var string */ protected $pk = 'id';
    /** @var array<string, string> */ protected $type = [
        'id' => 'integer',
        'tenant_id' => 'integer',
        'tenant_member_id' => 'integer',
        'job_id' => 'integer',
    ];

    /** @var array<string, string> Keep aligned with the module migrations and type overrides. */
    protected $schema = [
        'id' => 'integer',
        'tenant_id' => 'integer',
        'tenant_member_id' => 'integer',
        'job_id' => 'integer',
        'assigned_at' => 'datetime',
    ];
}
