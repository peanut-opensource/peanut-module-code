<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\ImportExport\Controller;

use app\adminapi\controller\BaseAdminController;
use PeanutAdmin\Modules\ImportExport\Service\OperationLogExportApplicationService;
use think\App;

final class OperationLogExportController extends BaseAdminController
{
    public function __construct(
        App $app,
        private readonly OperationLogExportApplicationService $exports,
    ) {
        parent::__construct($app);
    }

    public function export()
    {
        $context = $this->tenantAdminContext();
        $operation = $this->exports->submit(
            $context,
            $this->tenantAdminActor(),
            trim((string) $this->request->header('Idempotency-Key', '')),
        );
        return $this->data($operation->toPublicArray());
    }

    public function exportStatus()
    {
        $context = $this->tenantAdminContext();
        $operation = $this->exports->operation(
            $context,
            $this->tenantAdminActor(),
            (string) $this->request->get('operation_key', ''),
        );
        return $this->data($operation->toPublicArray());
    }

    public function exportDownload()
    {
        $context = $this->tenantAdminContext();
        $file = $this->exports->download(
            $context,
            $this->tenantAdminActor(),
            (string) $this->request->get('file_key', ''),
        );
        return redirect($file['url']);
    }
}
