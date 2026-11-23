<?php

declare(strict_types=1);

namespace app\api\controller;

use think\App;

use app\common\enum\decoration\DecorationEnum;
use app\common\services\decoration\DecorationReadService;
use app\common\exception\BusinessException;

class DecorationController extends BaseApiController
{
    public function __construct(App $app, protected readonly DecorationReadService $decoration)
    {
        parent::__construct($app);
    }
    public function mobilePage()
    {
        $type = (int) $this->request->get('type', DecorationEnum::MOBILE_HOME);
        if (!in_array($type, DecorationEnum::MOBILE_TYPES, true)) {
            throw BusinessException::invalid('DECORATION_PAGE_TYPE_INVALID', '移动端装修页面类型无效');
        }
        $context = $this->publicTenantContext('decoration.mobile-page');
        return $this->data($this->decoration->pageByType(
            $context,
            $type,
            'decoration.mobile-page',
        ));
    }

    public function tabbar()
    {
        $context = $this->publicTenantContext('decoration.config');
        return $this->data($this->decoration->tabbar(
            $context,
            true,
            'decoration.config',
        ));
    }

    public function pcPage()
    {
        $context = $this->publicTenantContext('decoration.pc-page');
        return $this->data($this->decoration->pageByType(
            $context,
            DecorationEnum::PC_HOME,
            'decoration.pc-page',
        ));
    }
}
