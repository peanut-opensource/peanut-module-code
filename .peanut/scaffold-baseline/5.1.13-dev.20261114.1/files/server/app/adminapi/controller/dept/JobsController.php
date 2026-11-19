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
        // Add/edit retain their raw arrays until the service's write boundary;
        // the other Trait actions cast identifiers before invoking that service.
        return in_array($scene, ['add', 'edit'], true)
            ? $params
            : $this->jobs->normalizeInput($params, $scene);
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
