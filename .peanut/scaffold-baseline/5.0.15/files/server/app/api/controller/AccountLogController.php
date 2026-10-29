<?php

declare(strict_types=1);

namespace app\api\controller;

use PeanutAdmin\Modules\Member\Contract\MemberQueries;

/** @property-read MemberQueries $members 当前 App 中声明式解析的控制器依赖。 */
class AccountLogController extends BaseApiController
{
    protected string $membersClass = MemberQueries::class;

    /** 账户流水 */
    public function lists()
    {
        $params = [
            'page_no'   => $this->request->get('page_no/d', 1),
            'page_size' => $this->request->get('page_size/d', 15),
        ];

        $result = $this->members->balanceLogsForCurrentMember($params['page_no'], $params['page_size']);
        return $this->data($result);
    }
}
