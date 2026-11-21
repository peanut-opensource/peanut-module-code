<?php

declare(strict_types=1);

namespace app\common\model;

/** Base model for Platform control-plane data, never Tenant-scoped. */
abstract class PlatformOwnedModel extends BaseModel {}
