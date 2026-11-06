<?php

declare(strict_types=1);

namespace Custom\Modules\AiAssistant\Model;

use app\common\model\TenantOwnedModel;

final class Quota extends TenantOwnedModel
{
    protected $name = 'ai_assistant_quota';
    protected $pk = 'id';
    protected $autoWriteTimestamp = false;
    protected $schema = ['id' => 'int','tenant_id' => 'int','title' => 'string','status' => 'string','version' => 'int','created_by' => 'int','details' => 'string','request_id' => 'string','created_at' => 'int','updated_at' => 'int','connection_id' => 'integer','period' => 'string','reserved_requests' => 'integer','confirmed_requests' => 'integer','unknown_requests' => 'integer',];
}
