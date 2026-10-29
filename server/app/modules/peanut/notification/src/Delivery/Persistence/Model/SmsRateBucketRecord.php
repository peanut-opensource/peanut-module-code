<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Notification\Delivery\Persistence\Model;

use PeanutAdmin\Kernel\Persistence\Model\TenantModel;

final class SmsRateBucketRecord extends TenantModel
{
    /** @var string */ protected $name = 'sms_rate_bucket';
    /** @var array<string, string> */ protected $type = [
        'tenant_id' => 'integer', 'window_seconds' => 'integer', 'send_count' => 'integer',
    ];
}
