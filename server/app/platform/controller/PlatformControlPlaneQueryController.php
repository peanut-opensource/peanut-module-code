<?php

declare(strict_types=1);

namespace app\platform\controller;

use think\App;
use think\facade\Db;

use app\common\http\PageResult;
use app\platform\query\PlatformControlPlaneQueryService;
use app\platform\query\PlatformRoleNodeSource;
use PeanutAdmin\Kernel\Authorization\Application\AdminAccessException;
use PeanutAdmin\Kernel\Authorization\Application\PageRequest;
use PeanutAdmin\Kernel\Module\ModuleAvailability;
use PeanutAdmin\Modules\Identity\Contract\PlatformRoleAuthorizationPreview;

final class PlatformControlPlaneQueryController extends BasePlatformController
{
    public function __construct(App $app, protected readonly PlatformControlPlaneQueryService $queries)
    {
        parent::__construct($app);
    }
    public function operators()
    {
        return $this->listQuery('operators');
    }

    public function roles()
    {
        return $this->listQuery('roles');
    }

    /** Read-only candidate evaluation; no role grants or catalogue changes are applied. */
    public function previewRolePermissions(
        PlatformRoleAuthorizationPreview $preview,
        ModuleAvailability $modules,
    ): \think\response\Json {
        if ($this->platformContext === null) {
            throw \app\common\http\ApiProblem::fromEnvelope('Platform authentication is required.', null, 40100);
        }
        return $this->data($preview->preview($this->platformContext->core, $this->request->post(), $modules));
    }

    /** Audits explicitly selected Platform roles, including roles with no current operator. */
    public function auditRoleCatalog(
        PlatformRoleAuthorizationPreview $preview,
        ModuleAvailability $modules,
        PlatformRoleNodeSource $source,
    ): \think\response\Json {
        if ($this->platformContext === null) {
            throw \app\common\http\ApiProblem::fromEnvelope('Platform authentication is required.', null, 40100);
        }
        // The client chooses only registered node keys. It never owns the
        // canonical Platform node/API graph or its generated-source version.
        // Keep the registered-source reads and Identity's existing equivalence
        // calculation in the same database read snapshot. This is not a writer
        // lock or a permission to migrate.
        return $this->data(Db::transaction(function () use ($source, $preview, $modules): array {
            $candidate = $source->authoritativeCandidate($this->request->post());
            return $preview->auditCatalog($this->platformContext->core, $candidate, $modules);
        }));
    }

    public function permissions()
    {
        return $this->listQuery('permissions');
    }

    public function audit()
    {
        return $this->listQuery('audit');
    }

    public function moduleStates()
    {
        return $this->listQuery('moduleStates');
    }

    public function owner()
    {
        if ($this->platformContext === null) {
            throw \app\common\http\ApiProblem::fromEnvelope('Platform authentication is required.', null, 40100);
        }
        return $this->data($this->queries->owner(
            $this->platformContext,
            $this->positiveInteger($this->request->get('tenant_id')),
        ));
    }

    private function listQuery(string $method)
    {
        if ($this->platformContext === null) {
            throw \app\common\http\ApiProblem::fromEnvelope('Platform authentication is required.', null, 40100);
        }
        $page = $this->positiveInteger($this->request->get('page', 1));
        $pageSize = $this->positiveInteger($this->request->get('page_size', 20));
        if ($pageSize > 100) {
            throw AdminAccessException::invalid('PAGE_SIZE_INVALID', 'Page size must be at most 100.');
        }
        $request = new PageRequest($page, $pageSize);
        $query = $this->queries;
        $result = $method === 'moduleStates'
            ? $query->moduleStates($this->platformContext, $this->positiveInteger($this->request->get('tenant_id')), $request)
            : $query->{$method}($this->platformContext, $request);
        return $this->dataLists(new PageResult($result['items'], $result['total'], $page, $pageSize));
    }

    private function positiveInteger(mixed $value): int
    {
        if ((!is_int($value) && !is_string($value))
            || preg_match('/^[1-9][0-9]*$/D', (string) $value) !== 1
            || filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
            throw AdminAccessException::invalid('ID_INVALID', 'A positive integer is required.');
        }
        return (int) $value;
    }

}
