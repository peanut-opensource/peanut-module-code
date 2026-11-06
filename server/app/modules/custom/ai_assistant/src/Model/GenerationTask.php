<?php

declare(strict_types=1);

namespace Custom\Modules\AiAssistant\Model;

use app\common\model\TenantOwnedModel;

final class GenerationTask extends TenantOwnedModel
{
    protected $name = 'ai_assistant_task';
    protected $autoWriteTimestamp = false;
    protected $schema = ['id' => 'int','tenant_id' => 'int','title' => 'string','status' => 'string','version' => 'int','created_by' => 'int','details' => 'string','request_id' => 'string','created_at' => 'int','updated_at' => 'int','source_snapshot' => 'string','sealed_config' => 'string','actor_binding' => 'string','job_key' => 'string','request_hash' => 'string',];
}
