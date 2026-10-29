<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Workflow\Persistence\Model;

use PeanutAdmin\Kernel\Persistence\Model\TenantModel;

final class WorkflowTaskIdentityLinkRecord extends TenantModel
{
    protected $name = 'workflow_task_identity_link';
    protected $type = ['sources_json' => 'json'];
}
