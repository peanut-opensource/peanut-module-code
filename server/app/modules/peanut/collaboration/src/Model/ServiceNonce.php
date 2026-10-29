<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Collaboration\Model;

use app\common\model\InstanceOwnedModel;

final class ServiceNonce extends InstanceOwnedModel
{
    protected $name = 'collaboration_service_nonce';
    protected $autoWriteTimestamp = false;
}
