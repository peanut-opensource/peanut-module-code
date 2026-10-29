<?php

declare(strict_types=1);

namespace app\common\model;

/** Base model for deployment-instance data, never Tenant-scoped. */
abstract class InstanceOwnedModel extends BaseModel {}
