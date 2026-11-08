<?php

declare(strict_types=1);

namespace app\adminapi\controller;

use app\adminapi\services\WorkbenchApplicationService;

class WorkbenchController extends BaseAdminController
{
    public function index(WorkbenchApplicationService $workbench)
    {
        return $this->data($workbench->index($this->tenantAdminContext()));
    }
}
