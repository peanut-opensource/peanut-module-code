<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Member\Controller;

use app\api\controller\BaseApiController;
use PeanutAdmin\Modules\Member\Contract\MemberQueries;
use think\App;

class MemberAccountLogController extends BaseApiController
{

    /** 账户流水 */

    public function __construct(
        App $app,
        private readonly MemberQueries $members,
    ) {
        parent::__construct($app);
    }

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
