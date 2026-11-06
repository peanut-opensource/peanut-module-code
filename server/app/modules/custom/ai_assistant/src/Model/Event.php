<?php

declare(strict_types=1);

namespace Custom\Modules\AiAssistant\Model;

use app\common\model\TenantOwnedModel;

final class Event extends TenantOwnedModel
{
    protected $name = 'ai_assistant_event';
    protected $autoWriteTimestamp = false;
    protected $schema = ['id' => 'int','tenant_id' => 'int','title' => 'string','status' => 'string','version' => 'int','created_by' => 'int','details' => 'string','request_id' => 'string','created_at' => 'int','updated_at' => 'int','parent_id' => 'integer','actor_type' => 'string',];
}
