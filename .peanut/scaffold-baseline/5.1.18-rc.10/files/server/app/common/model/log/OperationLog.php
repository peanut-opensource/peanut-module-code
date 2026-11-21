<?php

declare(strict_types=1);

namespace app\common\model\log;

use app\common\model\TenantOwnedModel;
use app\common\policy\audit\RedactionPolicy;

class OperationLog extends TenantOwnedModel
{
    protected $name = 'operation_log';
    protected $type = ['tenant_id' => 'integer'];

    // 仅有 create_time，无 update_time
    protected $updateTime = false;

    /** Older audit rows stay immutable; all query consumers see the current redaction policy. */
    public function getParamsAttr(mixed $value): mixed
    {
        if (is_array($value)) {
            return RedactionPolicy::sanitize($value);
        }
        if (!is_string($value)) {
            return $value;
        }
        $decoded = json_decode($value, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return $value;
        }
        $safe = RedactionPolicy::sanitize($decoded);
        return $safe === $decoded ? $value : RedactionPolicy::encode($safe);
    }
}
