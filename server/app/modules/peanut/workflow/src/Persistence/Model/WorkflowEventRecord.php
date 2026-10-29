<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Workflow\Persistence\Model;

use PeanutAdmin\Kernel\Persistence\Model\TenantModel;

final class WorkflowEventRecord extends TenantModel
{
    /** @var string */ protected $name = 'workflow_event';
    protected $type = ['metadata_json' => 'json', 'attachment_snapshots_json' => 'json', 'notification_fact_json' => 'json', 'notification_outbox_json' => 'json'];
    /** @var string */ protected $dateFormat = 'Y-m-d H:i:s.v';
}
