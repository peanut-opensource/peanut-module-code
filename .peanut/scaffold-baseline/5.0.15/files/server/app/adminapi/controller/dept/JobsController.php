<?php

declare(strict_types=1);

namespace app\adminapi\controller\dept;

use app\adminapi\controller\BaseAdminController;
use app\adminapi\services\dept\JobsApplicationService;
use app\common\traits\CrudTrait;
use PeanutAdmin\Kernel\Auth\TenantContext;
use think\response\Json;

/** @property-read JobsApplicationService $jobs 当前 App 中声明式解析的控制器依赖。 */
class JobsController extends BaseAdminController
{
    use CrudTrait;

    protected const CRUD_STATUS_FIELD = 'status';

    protected string $jobsClass = JobsApplicationService::class;

    protected function resolveCrudContext(): TenantContext
    {
        return $this->tenantAdminContext();
    }

    protected function crudService(): object
    {
        return $this->jobs;
    }

    protected function validatedInput(mixed $_context, string $scene, array $params): array
    {
        $params = $this->jobs->normalizeInput($params);
        $rules = $this->jobs->validationRules($scene);
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
        return $this->data($this->jobs->all($this->resolveCrudContext()));
    }
}
