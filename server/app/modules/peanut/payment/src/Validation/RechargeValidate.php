<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Payment\Validation;

use PeanutAdmin\Modules\Payment\Service\RefundEnum;
use app\common\validate\PageSizeRule;
use PeanutAdmin\Modules\Payment\Model\RechargeOrder;
use PeanutAdmin\Modules\Payment\Model\RefundRecord;
use app\common\validate\TenantContextValidate;

/** 充值列表、部分退款和失败重试参数验证。 */
class RechargeValidate extends TenantContextValidate
{
    use PageSizeRule;
    protected $rule = [
        'sn' => 'max:64',
        'pay_way' => 'in:1,2,3',
        'pay_status' => 'in:0,1',
        'start_time' => 'date',
        'end_time' => 'date|checkTimeRange',
        'page_no' => 'integer|gt:0',
        'page_size' => 'integer|gt:0|pageSizeMax',
        'page_type' => 'in:0,1',
        'page_start' => 'integer|gt:0',
        'page_end' => 'integer|gt:0',
        'export' => 'in:1,2',
        'file_name' => 'max:100',
        'recharge_id' => 'require|checkRecharge',
        'refund_amount' => 'float|gt:0',
        'record_id' => 'require|checkRecord',
    ];

    protected $message = [
        'end_time.checkTimeRange' => '搜索的时间范围不正确',
        'recharge_id.require' => '参数缺失',
        'record_id.require' => '参数缺失',
        'refund_amount.float' => '退款金额格式不正确',
        'refund_amount.gt' => '退款金额必须大于零',
    ];

    public function sceneLists(): self
    {
        return $this->only([
            'sn', 'pay_way', 'pay_status', 'start_time', 'end_time',
            'page_no', 'page_size', 'page_type', 'page_start', 'page_end',
            'export', 'file_name',
        ]);
    }

    public function sceneRefund(): self
    {
        return $this->only(['recharge_id', 'refund_amount']);
    }

    public function sceneAgain(): self
    {
        return $this->only(['record_id']);
    }

    protected function checkTimeRange($value, $rule, array $data): bool|string
    {
        if (empty($data['start_time']) || empty($value)) {
            return true;
        }

        return strtotime((string) $value) > strtotime((string) $data['start_time'])
            ? true
            : '搜索的时间范围不正确';
    }

    protected function checkRecharge($value): bool|string
    {
        $order = RechargeOrder::where([])->findOrEmpty((int) $value);
        if ($order->isEmpty()) {
            return '充值订单不存在';
        }
        if ((int) $order->pay_status !== RechargeOrder::PAY_STATUS_PAID) {
            return '当前订单不可退款';
        }
        return true;
    }

    protected function checkRecord($value): bool|string
    {
        $record = RefundRecord::where([])->findOrEmpty((int) $value);
        if ($record->isEmpty()) {
            return '退款记录不存在';
        }
        if ((int) $record->refund_status === RefundEnum::REFUND_SUCCESS) {
            return '该退款记录已退款成功';
        }
        if ((int) $record->refund_status !== RefundEnum::REFUND_ERROR) {
            return '退款正在处理中，请勿重复操作';
        }

        return true;
    }

}
