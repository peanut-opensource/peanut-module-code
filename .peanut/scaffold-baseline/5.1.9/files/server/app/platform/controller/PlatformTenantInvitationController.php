<?php

declare(strict_types=1);

namespace app\platform\controller;

use app\common\http\PageResult;
use app\platform\http\PlatformRequest;
use PeanutAdmin\Modules\Identity\Invitation\TenantOwnerInvitationAdminService;
use app\platform\validate\TenantOwnerInvitationValidate;
use PeanutAdmin\Kernel\Authorization\Application\PageRequest;

/** @property-read TenantOwnerInvitationAdminService $invitations 当前 App 中声明式解析的控制器依赖。 */
final class PlatformTenantInvitationController extends BasePlatformController
{
    protected string $invitationsClass = TenantOwnerInvitationAdminService::class;

    public function provision()
    {
        return $this->mutate('provision', function (array $params): array {
            return $this->invitations->provision(
                $this->platformContext->core,
                trim((string) $params['tenant_code']),
                trim((string) $params['tenant_name']),
                trim((string) $params['owner_email']),
                trim((string) $params['owner_display_name']),
                (int) ($params['expires_in_hours'] ?? 72),
            );
        });
    }

    public function invite()
    {
        return $this->mutate('invite', function (array $params): array {
            return $this->invitations->invite(
                $this->platformContext->core,
                (int) $params['tenant_id'],
                trim((string) $params['owner_email']),
                trim((string) $params['owner_display_name']),
                (int) ($params['expires_in_hours'] ?? 72),
            );
        });
    }

    public function lists()
    {
        if ($this->platformContext === null) {
            throw \app\common\http\ApiProblem::fromEnvelope('Platform authentication is required.', null, 40100);
        }
        $params = $this->request->get();
        $this->validate($params, TenantOwnerInvitationValidate::class . '.lists');
        $page = (int) ($params['page'] ?? 1);
        $pageSize = (int) ($params['page_size'] ?? 20);
        $result = $this->invitations->invitations(
            $this->platformContext->core,
            (int) $params['tenant_id'],
            new PageRequest($page, $pageSize),
        );
        return $this->dataLists(new PageResult($result['items'], $result['total'], $page, $pageSize));
    }

    public function resend()
    {
        return $this->mutate('resend', function (array $params): array {
            return $this->invitations->resend(
                $this->platformContext->core,
                (int) $params['invitation_id'],
                (int) ($params['expires_in_hours'] ?? 72),
            );
        });
    }

    public function revoke()
    {
        return $this->mutate('revoke', function (array $params): array {
            return $this->invitations->revoke(
                $this->platformContext->core,
                (int) $params['invitation_id'],
            );
        });
    }

    private function mutate(string $scene, callable $operation)
    {
        if ($this->platformContext === null) {
            throw \app\common\http\ApiProblem::fromEnvelope('Platform authentication is required.', null, 40100);
        }
        $params = $this->request->post();
        $this->validate($params, TenantOwnerInvitationValidate::class . '.' . $scene);
        return $this->data($operation($params));
    }
}
