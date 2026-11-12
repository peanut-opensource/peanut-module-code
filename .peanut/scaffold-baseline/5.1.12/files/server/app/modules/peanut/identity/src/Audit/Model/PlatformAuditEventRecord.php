<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Identity\Audit\Model;

use think\Model;

final class PlatformAuditEventRecord extends Model
{
    /** @var string */ protected $name = 'platform_audit_event';
    /** @var bool */ protected $autoWriteTimestamp = false;
    /** @var array<string, string> */ protected $type = [
        'before_json' => 'json',
        'after_json' => 'json',
        'metadata_json' => 'json',
    ];
    /** @var bool */ protected $jsonAssoc = true;
}
