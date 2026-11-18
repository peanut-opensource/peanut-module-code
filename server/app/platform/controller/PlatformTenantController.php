<?php

declare(strict_types=1);

namespace app\platform\controller;

use app\common\http\PageResult;
use app\platform\services\PlatformTenantQueryService;
use app\platform\services\TenantGovernanceService;
use app\platform\validate\PlatformTenantLifecycleValidate;
use PeanutAdmin\Kernel\Authorization\Application\AdminAccessException;
use PeanutAdmin\Kernel\Authorization\Application\PageRequest;
use PeanutAdmin\Modules\Identity\Tenancy\TenantStatus;
use think\App;

final class PlatformTenantController extends BasePlatformController
{
    public function __construct(
        App $app,
        private readonly TenantGovernanceService $tenantGovernance,
        private readonly PlatformTenantQueryService $tenantQueries,
    ) {
        parent::__construct($app);
    }

    public function provision()
    {
        if ($this->platformContext === null) {
            throw \app\common\http\ApiProblem::fromEnvelope('Platform authentication is required.', null, 40100);
        }

        $params = $this->request->post();
        $this->validate($params, PlatformTenantLifecycleValidate::class . '.provision');
        return $this->data($this->tenantGovernance->provision(
            $this->platformContext,
            trim((string) $params['tenant_code']),
            trim((string) $params['tenant_name']),
            trim((string) $params['owner_email']),
            isset($params['initial_password']) && (string) $params['initial_password'] !== ''
                ? (string) $params['initial_password']
                : null,
            trim((string) $params['owner_display_name']),
        ));
    }

    public function activate()
    {
        if ($this->platformContext === null) {
            throw \app\common\http\ApiProblem::fromEnvelope('Platform authentication is required.', null, 40100);
        }

        $params = $this->request->post();
        $this->validate($params, PlatformTenantLifecycleValidate::class . '.activate');
        return $this->data($this->tenantGovernance->transition(
            $this->platformContext,
            (int) $params['tenant_id'],
            (int) $params['expected_revision'],
            TenantStatus::Active,
            trim((string) $params['change_reason']),
        ));
    }

    public function suspend()
    {
        if ($this->platformContext === null) {
            throw \app\common\http\ApiProblem::fromEnvelope('Platform authentication is required.', null, 40100);
        }

        $params = $this->request->post();
        $this->validate($params, PlatformTenantLifecycleValidate::class . '.suspend');
        return $this->data($this->tenantGovernance->transition(
            $this->platformContext,
            (int) $params['tenant_id'],
            (int) $params['expected_revision'],
            TenantStatus::Suspended,
            trim((string) $params['change_reason']),
        ));
    }

    public function close()
    {
        if ($this->platformContext === null) {
            throw \app\common\http\ApiProblem::fromEnvelope('Platform authentication is required.', null, 40100);
        }

        $params = $this->request->post();
        $this->validate($params, PlatformTenantLifecycleValidate::class . '.close');
        return $this->data($this->tenantGovernance->transition(
            $this->platformContext,
            (int) $params['tenant_id'],
            (int) $params['expected_revision'],
            TenantStatus::Closed,
            trim((string) $params['change_reason']),
        ));
    }

    public function lists()
    {
        if ($this->platformContext === null) {
            throw \app\common\http\ApiProblem::fromEnvelope('Platform authentication is required.', null, 40100);
        }

        $page = $this->positiveInteger($this->request->get('page', 1), 'PAGE_INVALID');
        $pageSize = $this->positiveInteger($this->request->get('page_size', 20), 'PAGE_SIZE_INVALID');
        $result = $this->tenantQueries->tenants(
            $this->platformContext,
            new PageRequest($page, $pageSize),
        );

        return $this->dataLists(new PageResult($result['items'], $result['total'], $page, $pageSize));
    }

    public function detail()
    {
        if ($this->platformContext === null) {
            throw \app\common\http\ApiProblem::fromEnvelope('Platform authentication is required.', null, 40100);
        }

        $tenantId = $this->positiveInteger($this->request->get('id'), 'TENANT_ID_INVALID');
        return $this->data($this->tenantQueries->tenant(
            $this->platformContext,
            $tenantId,
        ));
    }

    private function positiveInteger(mixed $value, string $errorCode): int
    {
        if ((!is_int($value) && !is_string($value))
            || preg_match('/^[1-9][0-9]*$/D', (string) $value) !== 1
            || filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
            throw AdminAccessException::invalid($errorCode, 'A positive integer is required.');
        }
        return (int) $value;
    }
}
