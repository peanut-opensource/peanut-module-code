<?php

declare(strict_types=1);

namespace app\adminapi\controller\dept;

use app\adminapi\controller\BaseAdminController;
use app\adminapi\services\dept\DeptApplicationService;
use app\common\traits\CrudTrait;
use PeanutAdmin\Kernel\Auth\TenantContext;
use think\response\Json;

/** @property-read DeptApplicationService $departments 当前 App 中声明式解析的控制器依赖。 */
class DeptController extends BaseAdminController
{
    use CrudTrait;

    protected const CRUD_STATUS_FIELD = 'status';

    protected string $departmentsClass = DeptApplicationService::class;

    protected function resolveCrudContext(): TenantContext
    {
        return $this->tenantAdminContext();
    }

    protected function crudService(): object
    {
        return $this->departments;
    }

    protected function validatedInput(mixed $_context, string $scene, array $params): array
    {
        if (!array_key_exists('status', $params) && array_key_exists('is_disable', $params)) {
            $params['status'] = (int) $params['is_disable'] === 0 ? 1 : 0;
        }
        $rules = $this->departments->validationRules($scene);
        if (in_array($scene, ['detail', 'delete'], true)) {
            $rules = ['id' => $rules['id'] ?? 'require|integer|gt:0'];
        } elseif ($scene === 'status') {
            $rules = [
                'id' => $rules['id'] ?? 'require|integer|gt:0',
                'status' => $rules['status'] ?? 'require|in:0,1',
            ];
        }
        $this->validate($params, $rules);
        return $params;
    }

    protected function renderDetail(array $result): Json
    {
        return $this->data($result);
    }

    public function all()
    {
        return $this->data($this->departments->all($this->resolveCrudContext(), $this->request->get()));
    }

    public function leaderDept()
    {
        return $this->data($this->departments->leaderDept($this->resolveCrudContext(), $this->request->get()));
    }

    public function leaderCandidates()
    {
        return $this->data($this->departments->leaderCandidates($this->resolveCrudContext(), $this->request->get()));
    }
}
