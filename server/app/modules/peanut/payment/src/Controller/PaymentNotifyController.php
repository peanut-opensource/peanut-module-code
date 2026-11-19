<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Payment\Controller;

use app\api\controller\BaseApiController;
use PeanutAdmin\Modules\Payment\Service\PaymentCallbackApplicationService;
use PeanutAdmin\Modules\Payment\Infrastructure\Channel\CallbackRequest;
use app\common\http\RequestTrace;
use think\App;

class PaymentNotifyController extends BaseApiController
{
    public function __construct(
        App $app,
        private readonly PaymentCallbackApplicationService $application,
    ) {
        parent::__construct($app);
    }

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
