<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Identity\Contract;

use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Modules\Identity\Contract\Dto\EnrollDeviceCredentialInput;

interface DeviceCredentialEnrollment
{
    /** @return array{outcome:string,correlation_id:string,resource_key:?string,resource_revision:?string,data:?array,problem:?array} */
    public function enroll(TenantContext $actor, EnrollDeviceCredentialInput $input): array;
}
