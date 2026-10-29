<?php

declare(strict_types=1);

namespace app\api\controller;

use app\api\services\SmsApplicationService;
use app\api\validate\SmsValidate;

/** @property-read SmsApplicationService $sms 当前 App 中声明式解析的控制器依赖。 */
class SmsController extends BaseApiController
{
    protected string $smsClass = SmsApplicationService::class;


    public function sendCode()
    {
        $params = $this->request->post();
        $this->validate($params, SmsValidate::class . '.send');
        $this->sms->sendCode(
            $this->publicTenantContext('notice.verification.send'),
            $params,
        );
        return $this->success('发送成功');
    }
}
