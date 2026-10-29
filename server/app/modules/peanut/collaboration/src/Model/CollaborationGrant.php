<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Collaboration\Model;

use app\common\model\TenantOwnedModel;

/** Immutable connection authorization windows; no per-update journal. */
final class CollaborationGrant extends TenantOwnedModel
{
    protected $name = 'collaboration_grant';
    protected $autoWriteTimestamp = false;
}
