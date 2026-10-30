<?php

declare(strict_types=1);

namespace app\adminapi\controller\setting;

use app\adminapi\controller\BaseAdminController;
use app\adminapi\services\setting\TransactionSettingsApplicationService;
use app\common\exception\BusinessException;

/**
 * 交易设置
 * @property-read TransactionSettingsApplicationService $transactionSettings 当前 App 中声明式解析的控制器依赖。
 */
class TransactionSettingsController extends BaseAdminController
{
    protected string $transactionSettingsClass = TransactionSettingsApplicationService::class;

    public function getConfig()
    {
        return $this->data($this->transactionSettings->getConfig(
            $this->tenantAdminContext(),
        ));
    }

    public function setConfig()
    {
        $post = $this->request->post();
        if (array_diff(array_keys($post), ['cancel_unpaid_orders', 'cancel_unpaid_orders_times', 'verification_orders', 'verification_orders_times']) !== []) {
            throw BusinessException::invalid('TRANSACTION_INPUT_INVALID', '交易设置包含未知字段');
        }

        // 基础校验
        if (!in_array($post['cancel_unpaid_orders'] ?? null, [0, 1, '0', '1'], true)) {
            throw BusinessException::invalid('TRANSACTION_CANCEL_MODE_INVALID', '请选择系统取消待付款订单方式');
        }
        if (!in_array($post['verification_orders'] ?? null, [0, 1, '0', '1'], true)) {
            throw BusinessException::invalid('TRANSACTION_VERIFY_MODE_INVALID', '请选择系统自动核销订单方式');
        }
        if ((int) $post['cancel_unpaid_orders'] === 1 || array_key_exists('cancel_unpaid_orders_times', $post)) {
            $t = $post['cancel_unpaid_orders_times'] ?? null;
            if ((!is_int($t) && !is_string($t)) || filter_var($t, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 4294967295]]) === false) {
                throw BusinessException::invalid('TRANSACTION_CANCEL_DELAY_INVALID', '系统取消待付款订单时间须大于 0');
            }
        }
        if ((int) $post['verification_orders'] === 1 || array_key_exists('verification_orders_times', $post)) {
            $t = $post['verification_orders_times'] ?? null;
            if ((!is_int($t) && !is_string($t)) || filter_var($t, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 4294967295]]) === false) {
                throw BusinessException::invalid('TRANSACTION_VERIFY_DELAY_INVALID', '系统自动核销订单时间须大于 0');
            }
        }

        $this->transactionSettings->setConfig(
            $this->tenantAdminContext(),
            $post,
        );
        return $this->success('操作成功');
    }
}
