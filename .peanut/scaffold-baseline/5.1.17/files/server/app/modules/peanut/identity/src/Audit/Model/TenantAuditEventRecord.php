<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Identity\Audit\Model;

use PeanutAdmin\Kernel\Persistence\Model\TenantModel;

final class TenantAuditEventRecord extends TenantModel
{
    /** @var string */ protected $name = 'tenant_audit_event';
    /** @var array<string, string> */ protected $type = [
        'before_json' => 'json',
        'after_json' => 'json',
        'metadata_json' => 'json',
        'authorization_basis_json' => 'json',
    ];
    /** @var bool */ protected $jsonAssoc = true;
}
