<?php

declare(strict_types=1);

namespace app\adminapi\controller\decoration;

use app\adminapi\controller\BaseAdminController;
use app\adminapi\services\decoration\DecorationPageApplicationService;
use app\adminapi\validate\decoration\DecorationPageValidate;
use app\common\enum\decoration\DecorationEnum;

/** @property-read DecorationPageApplicationService $decorationPages 当前 App 中声明式解析的控制器依赖。 */
class DecorationPageController extends BaseAdminController
{
    protected string $decorationPagesClass = DecorationPageApplicationService::class;

    public function mobileLists()
    {
        return $this->data($this->decorationPages->lists(
            $this->tenantAdminContext(),
            DecorationEnum::MOBILE_TYPES,
        ));
    }

    public function mobileDetail()
    {
        return $this->detail(DecorationEnum::MOBILE_TYPES);
    }

    public function mobileSave()
    {
        return $this->save(DecorationEnum::MOBILE_TYPES);
    }

    public function pcDetail()
    {
        return $this->detail([DecorationEnum::PC_HOME]);
    }

    public function pcLists()
    {
        return $this->data($this->decorationPages->lists(
            $this->tenantAdminContext(),
            [DecorationEnum::PC_HOME],
        ));
    }

    public function pcSave()
    {
        return $this->save([DecorationEnum::PC_HOME]);
    }

    public function article()
    {
        $params = $this->validateInput($this->request->get(), DecorationPageValidate::class . '.article', ['limit'])->validated();
        return $this->data($this->decorationPages->articleOptions(
            $this->tenantAdminContext(),
            (int) ($params['limit'] ?? 20),
        ));
    }

    private function detail(array $allowedTypes)
    {
        $params = $this->validateInput($this->request->get(), DecorationPageValidate::class . '.detail', ['id'])->validated();
        return $this->data($this->decorationPages->detail(
            $this->tenantAdminContext(),
            (int) $params['id'],
            $allowedTypes,
        ));
    }

    private function save(array $allowedTypes)
    {
        $params = $this->validateInput($this->request->post(), DecorationPageValidate::class . '.save', ['id', 'type', 'data', 'meta'])->writable();
        $this->decorationPages->save(
            $this->tenantAdminContext(),
            $params,
            $allowedTypes,
        );
        return $this->success('保存成功');
    }
}
