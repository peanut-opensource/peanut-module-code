<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Workflow\Persistence\Model;

use PeanutAdmin\Kernel\Persistence\Model\TenantModel;

final class WorkflowWorkItemRecord extends TenantModel
{
    /** @var string */ protected $name = 'workflow_work_item';
    protected $type = ['sources_json' => 'json'];
    /** @var string */ protected $dateFormat = 'Y-m-d H:i:s.v';
}
