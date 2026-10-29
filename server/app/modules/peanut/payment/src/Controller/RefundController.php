<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Payment\Controller;

use app\adminapi\controller\BaseAdminController;
use PeanutAdmin\Modules\Payment\Service\RefundApplicationService;
use PeanutAdmin\Modules\Payment\Validation\RefundValidate;

/**
 * 退款控制器
 * @property-read RefundApplicationService $refunds 当前 App 中声明式解析的控制器依赖。
 */
class RefundController extends BaseAdminController
{
    protected string $refundsClass = RefundApplicationService::class;

    /** 退款统计（四个金额汇总） */
    public function stat()
    {
        return $this->data($this->refunds->stat($this->tenantAdminContext()));
    }

    /** 退款记录列表（分页） */
    public function record()
    {
        $params = $this->request->get();
        $this->validate($params, RefundValidate::class . '.record');
        return $this->data($this->refunds->lists($this->tenantAdminContext(), $params));
    }

    /** 退款日志（某条退款记录的操作流水） */
    public function log()
    {
        $params = $this->request->get();
        $this->validate($params, RefundValidate::class . '.log');
        $recordId = (int) $params['record_id'];
        return $this->data($this->refunds->refundLog(
            $this->tenantAdminContext(),
            $recordId,
        ));
    }
}
