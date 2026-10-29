<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Workflow\Persistence\Model;

use PeanutAdmin\Kernel\Persistence\Model\TenantModel;

final class WorkflowNodeExecutionRecord extends TenantModel
{
    protected $name = 'workflow_node_execution';
    protected $type = ['input_json' => 'json', 'action_input_json' => 'json', 'people_json' => 'json', 'output_json' => 'json', 'effect_receipt_json' => 'json'];
}
