<?php

declare(strict_types=1);

namespace Custom\Modules\AiAssistant\Model;

use app\common\model\TenantOwnedModel;

/**
 * @property int|string $created_by
 * @property string $actor_type
 * @property int|string $created_at
 * @property string $details
 * @property int|string $id
 * @property int|string $parent_id
 * @property string $request_id
 * @property string $status
 * @property string $title
 * @property int|string $updated_at
 * @property int|string $version
 */
final class Event extends TenantOwnedModel
{
    protected $name = 'ai_assistant_event';
    protected $pk = 'id';
    protected $autoWriteTimestamp = false;
    protected $schema = ['id' => 'int','tenant_id' => 'int','title' => 'string','status' => 'string','version' => 'int','created_by' => 'int','details' => 'string','request_id' => 'string','created_at' => 'int','updated_at' => 'int','parent_id' => 'integer','actor_type' => 'string',];
}
