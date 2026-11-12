<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Identity\Persistence\Model;

use think\Model;

final class TenantSessionToken extends Model
{
    /** @var string */ protected $name = 'tenant_session_token';
    /** @var string */ protected $pk = 'id';
    /** @var bool */ protected $autoWriteTimestamp = false;
    /** @var array<string, string> */ protected $type = [
        'id' => 'integer',
        'session_id' => 'integer',
    ];

    /** @var array<string, string> Keep aligned with the Identity migrations. */
    protected $schema = [
        'id' => 'integer',
        'session_id' => 'integer',
        'token_type' => 'string',
        'token_hash' => 'string',
        'status' => 'string',
        'parent_token_id' => 'bigint',
        'replaced_by_token_id' => 'bigint',
        'expires_at' => 'datetime',
        'used_at' => 'datetime',
        'revoked_at' => 'datetime',
        'created_at' => 'datetime',
    ];
}
