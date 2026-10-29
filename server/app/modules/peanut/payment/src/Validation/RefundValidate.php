<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Payment\Validation;

use app\common\validate\PageSizeRule;
use think\Validate;

/** 退款记录与退款日志查询参数验证。 */
class RefundValidate extends Validate
{
    use PageSizeRule;

    protected $rule = [
        'sn' => 'max:32',
        'order_sn' => 'max:64',
        'refund_type' => 'in:1',
        'refund_status' => 'in:0,1,2',
        'start_time' => 'date',
        'end_time' => 'date|checkTimeRange',
        'page_no' => 'integer|gt:0',
        'page_size' => 'integer|gt:0|pageSizeMax',
        'page_type' => 'in:0,1',
        'export' => 'in:1,2',
        'record_id' => 'require|integer|gt:0',
    ];

    protected $message = [
        'end_time.checkTimeRange' => '搜索的时间范围不正确',
        'record_id.require' => '参数缺失',
    ];

    public function sceneRecord(): self
    {
        return $this->only([
            'sn', 'order_sn', 'refund_type', 'refund_status',
            'start_time', 'end_time', 'page_no', 'page_size',
            'page_type', 'export',
        ]);
    }

    public function sceneLog(): self
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

}
