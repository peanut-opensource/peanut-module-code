<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Document\Model;

use app\common\model\TenantOwnedModel;

/**
 * @property int|string $auth_revision
 * @property int $collaboration_enabled
 * @property string $content_kind
 * @property int|string $id
 * @property int|string $lease_epoch
 * @property string $owner_module_key
 * @property string $owner_object_id
 * @property string $owner_object_type
 * @property string $schema_version
 * @property string $slot
 * @property int|string $state_seq
 * @property string $status
 * @property int|string $tenant_id
 */
final class Document extends TenantOwnedModel
{
    protected $name = 'document';
    protected $autoWriteTimestamp = false;
}
