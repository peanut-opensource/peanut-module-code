<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Identity\access\SourceRead\Model;

use think\Model;

final class SourceReadGrantRecord extends Model
{
    /** @var string */ protected $name = 'source_read_grant';
    /** @var bool */ protected $autoWriteTimestamp = false;
    /** @var list<string> */ protected $json = ['fields_json'];
    /** @var bool */ protected $jsonAssoc = true;
    /** @var array<string,string> */ protected $type = [
        'id' => 'integer',
        'source_tenant_id' => 'integer',
        'recipient_tenant_id' => 'integer',
        'object_id' => 'integer',
        'revision' => 'integer',
    ];
}
