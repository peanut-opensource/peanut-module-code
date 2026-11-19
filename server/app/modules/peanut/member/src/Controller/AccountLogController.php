<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Member\Controller;

use app\adminapi\controller\BaseAdminController;
use PeanutAdmin\Modules\Member\Contract\MemberAdministration;
use PeanutAdmin\Modules\Member\Validation\AccountLogValidate;
use PeanutAdmin\Modules\Member\Value\AccountLogEnum;
use think\App;

class AccountLogController extends BaseAdminController
{
    public function __construct(
        App $app,
        private readonly MemberAdministration $members,
    ) {
        parent::__construct($app);
    }

    public function lists()
    {
        $params = $this->request->get();
        $this->validate($params, AccountLogValidate::class . '.lists');
        return $this->data($this->members->balanceLogs($params));
    }

    public function getUmChangeType()
    {
        return $this->data(AccountLogEnum::getUserMoneyChangeTypeDesc());
    }
}
