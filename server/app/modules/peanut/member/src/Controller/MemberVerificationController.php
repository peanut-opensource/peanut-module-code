<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Member\Controller;

use app\api\controller\BaseApiController;
use PeanutAdmin\Modules\Member\Service\MemberVerificationService;
use PeanutAdmin\Modules\Member\Validation\MemberVerificationValidate;

/** @property-read MemberVerificationService $sms 当前 App 中声明式解析的控制器依赖。 */
class MemberVerificationController extends BaseApiController
{
    protected string $smsClass = MemberVerificationService::class;


    public function sendCode()
    {
        $params = $this->request->post();
        $this->validate($params, MemberVerificationValidate::class . '.send');
        $this->sms->sendCode(
            $this->publicTenantContext('notice.verification.send'),
            $params,
        );
        return $this->success('发送成功');
    }
}
