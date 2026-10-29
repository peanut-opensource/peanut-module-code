<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Workflow\Persistence\Model;

use PeanutAdmin\Kernel\Persistence\Model\TenantModel;

final class WorkflowDefinitionRecord extends TenantModel
{
    /** @var string */ protected $name = 'workflow_definition';
    protected $type = ['draft_graph_json' => 'json', 'layout_json' => 'json', 'managed_template_json' => 'json'];
    /** @var string */ protected $dateFormat = 'Y-m-d H:i:s.v';
}
