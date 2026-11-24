<?php

declare(strict_types=1);

namespace app\adminapi\controller\log;

use think\App;
use app\adminapi\controller\BaseAdminController;
use app\common\http\PageResult;
use app\adminapi\services\log\OperationLogApplicationService;
use app\common\contract\authorization\AdminAuthorizationQuery;
use app\common\exception\BusinessException;
use app\common\infrastructure\module\ModuleExecutionBoundary;

class OperationLogController extends BaseAdminController
{
    public function __construct(
        App $app,
        private readonly OperationLogApplicationService $operationLogs,
        private readonly ModuleExecutionBoundary $modules,
        private readonly AdminAuthorizationQuery $authorization,
    ) {
        parent::__construct($app);
    }

    public function lists()
    {
        $context = $this->tenantAdminContext();
        $params = $this->request->get();
        $export = (int) ($params['export'] ?? 0);
        if ($export > 0) {
            $this->assertExportModule();
        }
        if (($export === 1 || $export === 2)
            && !$this->authorization->decide(
                $context,
                $this->tenantAdminActor(),
                'peanut.import-export.operation-log.export',
            )->allowed
        ) {
            throw BusinessException::forbidden('OPERATION_LOG_EXPORT_DENIED', '无权导出操作日志');
        }
        $res = $this->operationLogs->lists($context, $params);
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
