<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Workflow\Persistence\Model;

use PeanutAdmin\Kernel\Persistence\Model\TenantModel;

final class WorkflowDefinitionVersionRecord extends TenantModel
{
    /** @var string */ protected $name = 'workflow_definition_version';
    protected $type = ['graph_json' => 'json'];
    /** @var string */ protected $dateFormat = 'Y-m-d H:i:s.v';
}
