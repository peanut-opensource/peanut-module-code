<?php

declare(strict_types=1);

namespace Custom\Modules\AiAssistant\Model;

use app\common\model\TenantOwnedModel;

/**
 * @property string $details
 * @property string|null $candidate
 * @property int|string $id
 * @property string $request_hash
 * @property string $request_id
 * @property string $status
 */
final class TextGeneration extends TenantOwnedModel
{
    protected $name = 'ai_assistant_text_generation';
    protected $pk = 'id';
    protected $autoWriteTimestamp = false;
    protected $schema = [
        'id' => 'int', 'tenant_id' => 'int', 'created_by' => 'int', 'request_id' => 'string',
        'request_hash' => 'string', 'status' => 'string', 'details' => 'string', 'candidate' => 'string',
        'created_at' => 'int', 'updated_at' => 'int',
    ];
}
