<?php

declare(strict_types=1);

namespace Custom\Modules\AiAssistant\Model;

use app\common\model\TenantOwnedModel;
use think\model\concern\SoftDelete;

final class Configuration extends TenantOwnedModel
{
    use SoftDelete;
    protected $deleteTime = 'deleted_at';
    protected $defaultSoftDelete = 0;
    protected $name = 'ai_assistant_config';
    protected $pk = 'id';
    protected $autoWriteTimestamp = false;
    protected $schema = ['id' => 'int','tenant_id' => 'int','title' => 'string','status' => 'string','version' => 'int','created_by' => 'int','details' => 'string','request_id' => 'string','created_at' => 'int','updated_at' => 'int','kind' => 'string','secret_payload' => 'string','deleted_at' => 'integer',];
}
