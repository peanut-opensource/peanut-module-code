<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Member\Controller;

use app\api\controller\BaseApiController;
use PeanutAdmin\Modules\Member\Service\MemberVerificationService;
use PeanutAdmin\Modules\Member\Validation\MemberVerificationValidate;
use think\App;

class MemberVerificationController extends BaseApiController
{
    public function __construct(
        App $app,
        private readonly MemberVerificationService $sms,
    ) {
        parent::__construct($app);
    }

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
