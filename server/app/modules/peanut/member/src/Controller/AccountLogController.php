<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Member\Controller;

use app\adminapi\controller\BaseAdminController;
use PeanutAdmin\Modules\Member\Contract\MemberAdministration;
use PeanutAdmin\Modules\Member\Validation\AccountLogValidate;
use app\common\enum\AccountLogEnum;

/** @property-read MemberAdministration $members 当前 App 中声明式解析的控制器依赖。 */
class AccountLogController extends BaseAdminController
{
    protected string $membersClass = MemberAdministration::class;

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
