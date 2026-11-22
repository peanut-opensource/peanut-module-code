<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Collaboration\Model;

use app\common\model\TenantOwnedModel;

/**
 * @property int|string $document_auth_revision
 * @property int|string $document_id
 * @property int|string $id
 * @property string $issued_identity_json
 * @property string $owner_reference_json
 * @property int|string $tenant_id
 * @property string $edition
 * @property string $instance_id
 * @property string $mode
 */
final class CollaborationTicket extends TenantOwnedModel
{
    protected $name = 'collaboration_ticket';
    protected $autoWriteTimestamp = false;
}
