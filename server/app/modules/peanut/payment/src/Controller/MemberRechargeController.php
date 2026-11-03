<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Payment\Controller;

use app\api\controller\BaseApiController;
use PeanutAdmin\Modules\Payment\Service\MemberRechargeApplicationService;
use PeanutAdmin\Modules\Payment\Validation\MemberRechargeValidate;

/** @property-read MemberRechargeApplicationService $recharges 当前 App 中声明式解析的控制器依赖。 */
class MemberRechargeController extends BaseApiController
{
    protected string $rechargesClass = MemberRechargeApplicationService::class;

    public function config()
    {
        $params = ['terminal' => $this->request->get('terminal')];
        $this->validate($params, MemberRechargeValidate::class . '.config');
        return $this->data($this->recharges->config($this->memberContext(), $this->memberId, (int) $params['terminal']));
    }

    public function create()
    {
        $params = $this->request->post();
        $this->validate($params, MemberRechargeValidate::class . '.create');
        return $this->data($this->recharges->create($this->memberContext(), $this->memberId, $params));
    }

    public function prepay()
    {
        $params = $this->request->post();
        $this->validate($params, MemberRechargeValidate::class . '.prepay');
        $payWay = (int) $params['pay_way'];
        $context = $this->memberContext();
        return $this->data($this->recharges->prepay(
            $context,
            $this->memberId,
            (int) $params['order_id'],
            $payWay,
            rtrim((string) $this->request->domain(), '/'),
            (string) $this->request->ip(),
            '',
        ));
    }

    public function detail()
    {
        $params = ['order_id' => $this->request->get('order_id')];
        $this->validate($params, MemberRechargeValidate::class . '.detail');
        return $this->data($this->recharges->detail($this->memberContext(), $this->memberId, (int) $params['order_id']));
    }

    public function lists()
    {
        $params = [
            'page_no' => $this->request->get('page_no/d', 1),
            'page_size' => $this->request->get('page_size/d', 15),
        ];
        $this->validate($params, MemberRechargeValidate::class . '.lists');
        $result = $this->recharges->lists($this->memberContext(), $this->memberId, $params);
        return $this->data($result);
    }
}
