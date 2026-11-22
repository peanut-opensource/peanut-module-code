<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Collaboration\Model;

use app\common\model\TenantOwnedModel;

/**
 * @property string $authorization_revision
 * @property string $connection_key
 * @property int|string $id
 * @property string $lease_expires_at
 * @property string $mode
 * @property int|string $room_id
 * @property int|string $tenant_id
 * @property int|string $ticket_id
 */
final class CollaborationConnection extends TenantOwnedModel
{
    protected $name = 'collaboration_connection';
    protected $autoWriteTimestamp = false;
}
