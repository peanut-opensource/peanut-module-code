<?php

declare(strict_types=1);

namespace app\platform\controller;

use app\common\http\PageResult;
use app\platform\query\PlatformControlPlaneQueryService;
use PeanutAdmin\Kernel\Authorization\Application\AdminAccessException;
use PeanutAdmin\Kernel\Authorization\Application\PageRequest;

/** @property-read PlatformControlPlaneQueryService $queries 当前 App 中声明式解析的控制器依赖。 */
final class PlatformControlPlaneQueryController extends BasePlatformController
{
    protected string $queriesClass = PlatformControlPlaneQueryService::class;

    public function operators()
    {
        return $this->listQuery('operators');
    }

    public function roles()
    {
        return $this->listQuery('roles');
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
        $candidate = is_int($value) ? (string) $value : trim((string) $value);
        if (preg_match('/^[1-9][0-9]*$/D', $candidate) !== 1 || filter_var($candidate, FILTER_VALIDATE_INT) === false) {
            throw AdminAccessException::invalid('ID_INVALID', 'A positive integer is required.');
        }
        return (int) $candidate;
    }

}
