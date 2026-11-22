<?php

declare(strict_types=1);

namespace Custom\Modules\AiAssistant\Model;

use app\common\model\TenantOwnedModel;
use think\model\concern\SoftDelete;

/**
 * @property int|string $created_by
 * @property int|string $created_at
 * @property string $details
 * @property int|string $id
 * @property string $kind
 * @property string $request_id
 * @property string|null $secret_payload
 * @property string $status
 * @property string $title
 * @property int|string $updated_at
 * @property int|string $version
 */
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
