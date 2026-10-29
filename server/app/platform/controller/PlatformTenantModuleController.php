<?php

declare(strict_types=1);

namespace app\platform\controller;

use app\platform\http\PlatformRequest;
use app\platform\services\module\PlatformTenantModuleService;
use app\platform\validate\PlatformTenantModuleValidate;
use DateTimeImmutable;

/** @property-read PlatformTenantModuleService $tenantModules 当前 App 中声明式解析的控制器依赖。 */
final class PlatformTenantModuleController extends BasePlatformController
{
    protected string $tenantModulesClass = PlatformTenantModuleService::class;

    public function enable()
    {
        if ($this->platformContext === null) {
            throw \app\common\http\ApiProblem::fromEnvelope('Platform authentication is required.', null, 40100);
        }

        $params = $this->request->post();
        $this->validate($params, PlatformTenantModuleValidate::class . '.enable');
        return $this->data($this->tenantModules->enable(
            PlatformRequest::bearerToken($this->request),
            (int) $params['tenant_id'],
            trim((string) $params['module_key']),
            is_array($params['config'] ?? null) ? $params['config'] : [],
            'manual',
            $this->optionalDate($params['effective_at'] ?? null),
            $this->optionalDate($params['expires_at'] ?? null),
            trim((string) $params['change_reason']),
            $this->platformContext->core->requestId,
        ));
    }

    public function disable()
    {
        if ($this->platformContext === null) {
            throw \app\common\http\ApiProblem::fromEnvelope('Platform authentication is required.', null, 40100);
        }

        $params = $this->request->post();
        $this->validate($params, PlatformTenantModuleValidate::class . '.disable');
        return $this->data($this->tenantModules->disable(
            PlatformRequest::bearerToken($this->request),
            (int) $params['tenant_id'],
            trim((string) $params['module_key']),
            trim((string) $params['change_reason']),
            $this->platformContext->core->requestId,
        ));
    }

    private function optionalDate(mixed $value): ?DateTimeImmutable
    {
        $candidate = trim((string) $value);
        if ($candidate === '') {
            return null;
        }
        $date = DateTimeImmutable::createFromFormat(DateTimeImmutable::ATOM, $candidate);
        if (!$date instanceof DateTimeImmutable || $date->format(DateTimeImmutable::ATOM) !== $candidate) {
            throw new \InvalidArgumentException('Invalid date.');
        }
        return $date;
    }
}
