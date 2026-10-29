<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Article\Controller;

use app\adminapi\controller\BaseAdminController;
use think\response\Json;

final class HelloController extends BaseAdminController
{
    public function index(): Json
    {
        return $this->data(['message' => 'Hello']);
    }
}
