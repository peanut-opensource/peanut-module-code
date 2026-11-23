<?php

declare(strict_types=1);

namespace app\adminapi\controller\config;

use think\App;

use app\adminapi\controller\BaseAdminController;
use app\common\services\readiness\FirstRunReadinessHost;
use think\response\Json;

final class ReadinessController extends BaseAdminController
{
    public function __construct(App $app, protected readonly FirstRunReadinessHost $readiness)
    {
        parent::__construct($app);
    }
    public function checklist(): Json
    {
        return $this->data($this->readiness->checklist(
            $this->tenantAdminContext(),
            (string) $this->request->domain(),
            (string) config('deployment.mode'),
        ));
    }
}
