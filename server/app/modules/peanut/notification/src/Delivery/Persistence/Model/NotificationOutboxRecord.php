<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Notification\Delivery\Persistence\Model;

use PeanutAdmin\Kernel\Persistence\Model\TenantModel;

final class NotificationOutboxRecord extends TenantModel
{
    /** @var string */ protected $name = 'notification_outbox';
    /** @var array<string, string> */ protected $type = [
        'id' => 'integer', 'tenant_id' => 'integer', 'message_id' => 'integer', 'revision' => 'integer',
    ];
}
