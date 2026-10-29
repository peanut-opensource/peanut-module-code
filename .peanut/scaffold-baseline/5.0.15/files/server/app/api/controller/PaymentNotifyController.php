<?php

declare(strict_types=1);

namespace app\api\controller;

use app\api\services\PaymentCallbackApplicationService;
use app\common\dto\payment\CallbackRequest;
use app\common\http\RequestTrace;

/**
 * 支付回调 HTTP 适配：保留签名原文和渠道确认格式，不在控制器编排入账。
 * @property-read PaymentCallbackApplicationService $application 当前 App 中声明式解析的控制器依赖。
 */
class PaymentNotifyController extends BaseApiController
{
    protected string $applicationClass = PaymentCallbackApplicationService::class;

    public function wechat()
    {
        $this->application->wechat(
            new CallbackRequest((string) $this->request->getContent(), (array) $this->request->header()),
            (string) $this->request->route('binding'),
            $this->operationId(),
        );
        return json(['code' => 'SUCCESS', 'message' => '成功']);
    }

    public function alipay()
    {
        $this->application->alipay(
            new CallbackRequest('', [], $this->request->post()),
            (string) $this->request->route('binding'),
            $this->operationId(),
        );
        return response('success');
    }

    private function operationId(): string
    {
        return RequestTrace::id($this->executionContext(), $this->request, 'payment');
    }
}
