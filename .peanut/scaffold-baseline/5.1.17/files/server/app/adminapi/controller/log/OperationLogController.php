<?php

declare(strict_types=1);

namespace app\adminapi\controller\log;

use think\App;
use app\adminapi\controller\BaseAdminController;
use app\common\http\PageResult;
use app\adminapi\services\log\OperationLogApplicationService;
use app\common\infrastructure\module\ModuleExecutionBoundary;

class OperationLogController extends BaseAdminController
{
    public function __construct(
        App $app,
        private readonly OperationLogApplicationService $operationLogs,
        private readonly ModuleExecutionBoundary $modules,
    ) {
        parent::__construct($app);
    }

    public function lists()
    {
        if ((int) $this->request->get('export', 0) > 0) {
            $this->assertExportModule();
        }
        $res = $this->operationLogs->lists(
            $this->tenantAdminContext(),
            $this->request->get(),
        );
        if (!$res instanceof PageResult) {
            return $this->data($res);
        }
        return $this->data($res);
    }

    public function clear()
    {
        $this->operationLogs->clear(
            $this->tenantAdminContext(),
            $this->adminId,
            (string) ($this->adminInfo['username'] ?? ''),
            (string) $this->request->ip(),
        );
        return $this->success('操作成功');
    }

    private function assertExportModule(): void
    {
        $this->modules->assertHttp('peanut.import-export', 'http.admin.export');
    }

}
