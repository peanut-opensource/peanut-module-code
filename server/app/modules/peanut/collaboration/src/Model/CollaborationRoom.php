<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Collaboration\Model;

use app\common\model\TenantOwnedModel;

/**
 * @property string $binding_json
 * @property int|string $document_id
 * @property string $edition
 * @property string|null $holder_key
 * @property int|string $id
 * @property string $instance_id
 * @property int|string $lease_epoch
 * @property string $lease_expires_at
 * @property string $room_key
 * @property string $service_instance_id
 * @property string $status
 * @property int|string $tenant_id
 */
final class CollaborationRoom extends TenantOwnedModel
{
    protected $name = 'collaboration_room';
    protected $autoWriteTimestamp = false;
}
