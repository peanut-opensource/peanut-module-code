<?php

declare(strict_types=1);

namespace app\adminapi\controller\auth;

use app\adminapi\controller\BaseAdminController;
use app\adminapi\services\auth\LoginApplicationService;
use app\common\contract\authorization\AdminAuthorizationQuery;
use app\common\dto\authorization\AdminPrincipal;
use app\adminapi\http\AdminTokenExtractor;
use app\adminapi\validate\auth\LoginValidate;
use PeanutAdmin\Modules\Identity\Policy\DemoAccountPolicy;
use app\common\exception\BusinessException;
use think\App;
use PeanutAdmin\Modules\Settings\Service\WebsiteConfigService;

class LoginController extends BaseAdminController
{
    public function __construct(
        App $app,
        private readonly AdminAuthorizationQuery $authorization,
        private readonly LoginApplicationService $loginApplication,
        private readonly DemoAccountPolicy $demoAccounts,
        private readonly WebsiteConfigService $website,
    ) {
        parent::__construct($app);
    }

    public function login()
    {
        $params = $this->request->post();

        // 管理端登录表单提交 username，业务层统一映射为管理员账号。
        $params['account']  = trim((string) ($params['account'] ?? $params['username'] ?? ''));
        $params['terminal'] = (int) ($params['terminal'] ?? 1);

        $this->validate($params, LoginValidate::class);
        return $this->data($this->loginApplication->login($this->request, $params));
    }

    public function info()
    {
        $admin = $this->adminInfo;
        if ($admin === []) {
            throw BusinessException::notFound('ADMIN_PRINCIPAL_NOT_FOUND', '管理员不存在');
        }
        $roleNames = array_column($admin['roles'] ?? [], 'name');
        $accessData = $this->authorization->accessData(
            $this->executionContext()->tenantAdmin(),
            AdminPrincipal::fromArray($admin),
        );

        return $this->data([
            'id'          => $admin['id'],
            'username'    => $admin['username'],
            'nickname'    => $admin['nickname'],
            // 管理端展示与鉴权所需字段
            'name'        => $admin['name'],
            'avatar'      => $admin['avatar'],
            // 前端路由守卫用标量 role（'admin' 可访问全部 demo 路由）
            'role'        => $admin['root'] ? 'admin' : 'user',
            'root'        => $admin['root'],
            'roles'       => $roleNames,
            'menu'        => $accessData->menu,
            'permissions' => $accessData->permissions,
            'tenantName' => $admin['tenant_name'],
            'website' => $this->website->get($this->tenantAdminContext()),
            'canSwitchTenant' => !$this->executionContext()->tenantEntryBound()
                && ($admin['switchable_tenant_count'] ?? 0) > 1,
            'demoMode' => $this->demoAccounts->isDemoEmail((string) $admin['username']),
        ]);
    }

    public function logout()
    {
        $token = AdminTokenExtractor::tokenFromRequest($this->request);
        if ($token !== '') {
            $this->loginApplication->logout($token);
        }

        return $this->success('退出成功');
    }
}
