<?php

declare(strict_types=1);

namespace app\platform\controller;

use app\platform\validate\PlatformAccessValidate;
use PeanutAdmin\Kernel\Context\PlatformContext;
use PeanutAdmin\Modules\Identity\Platform\PlatformOperatorStatus;
use PeanutAdmin\Modules\Identity\Platform\Application\PlatformAccessAdminService;

/** @property-read PlatformAccessAdminService $platformAccess 当前 App 中声明式解析的控制器依赖。 */
final class PlatformAccessController extends BasePlatformController
{
    protected string $platformAccessClass = PlatformAccessAdminService::class;

    public function createOperator()
    {
        return $this->mutate('createOperator', function (array $params, PlatformContext $context): array {
            return $this->platformAccess->createOperator(
                $context,
                trim((string) $params['email']),
                trim((string) $params['display_name']),
                isset($params['initial_password']) && (string) $params['initial_password'] !== ''
                    ? (string) $params['initial_password']
                    : null,
            );
        });
    }

    public function updateOperator()
    {
        return $this->mutate('updateOperator', function (array $params, PlatformContext $context): array {
            return $this->platformAccess->updateOperator(
                $context,
                (int) $params['operator_id'],
                (int) $params['expected_revision'],
                trim((string) $params['display_name']),
                trim((string) $params['change_reason']),
            );
        });
    }

    public function replaceOperatorRoles()
    {
        return $this->mutate('replaceOperatorRoles', function (array $params, PlatformContext $context): array {
            return $this->platformAccess->replaceOperatorRoles(
                $context,
                (int) $params['operator_id'],
                array_values(array_map(static fn(mixed $id): int => (int) $id, $params['role_ids'])),
                (int) $params['expected_revision'],
                trim((string) $params['change_reason']),
            );
        });
    }

    public function activateOperator()
    {
        return $this->transitionOperator(PlatformOperatorStatus::Active, 'activateOperator');
    }

    public function suspendOperator()
    {
        return $this->transitionOperator(PlatformOperatorStatus::Suspended, 'suspendOperator');
    }

    public function closeOperator()
    {
        return $this->transitionOperator(PlatformOperatorStatus::Closed, 'closeOperator');
    }

    public function createRole()
    {
        return $this->mutate('createRole', function (array $params, PlatformContext $context): array {
            return $this->platformAccess->createRole(
                $context,
                trim((string) $params['key']),
                trim((string) $params['name']),
                isset($params['description']) && trim((string) $params['description']) !== ''
                    ? trim((string) $params['description'])
                    : null,
            );
        });
    }

    public function updateRole()
    {
        return $this->mutate('updateRole', function (array $params, PlatformContext $context): array {
            return $this->platformAccess->updateRole(
                $context,
                (int) $params['role_id'],
                (int) $params['expected_revision'],
                trim((string) $params['name']),
                isset($params['description']) && trim((string) $params['description']) !== ''
                    ? trim((string) $params['description'])
                    : null,
                trim((string) $params['change_reason']),
            );
        });
    }

    public function archiveRole()
    {
        return $this->mutate('archiveRole', function (array $params, PlatformContext $context): array {
            return $this->platformAccess->archiveRole(
                $context,
                (int) $params['role_id'],
                (int) $params['expected_revision'],
                trim((string) $params['change_reason']),
            );
        });
    }

    public function replaceRolePermissions()
    {
        return $this->mutate('replaceRolePermissions', function (array $params, PlatformContext $context): array {
            return $this->platformAccess->replaceRolePermissions(
                $context,
                (int) $params['role_id'],
                array_values(array_map(static fn(mixed $key): string => trim((string) $key), $params['permission_keys'])),
                (int) $params['expected_revision'],
                trim((string) $params['change_reason']),
            );
        });
    }

    private function transitionOperator(PlatformOperatorStatus $status, string $scene)
    {
        return $this->mutate($scene, function (array $params, PlatformContext $context) use ($status): array {
            return $this->platformAccess->transitionOperator(
                $context,
                (int) $params['operator_id'],
                (int) $params['expected_revision'],
                $status,
                trim((string) $params['change_reason']),
            );
        });
    }

    private function mutate(string $scene, callable $operation)
    {
        if ($this->platformContext === null) {
            throw \app\common\http\ApiProblem::fromEnvelope('Platform authentication is required.', null, 40100);
        }

        $params = $this->request->post();
        $this->validate($params, PlatformAccessValidate::class . '.' . $scene);
        return $this->data($operation($params, $this->platformContext->core));
    }
}
