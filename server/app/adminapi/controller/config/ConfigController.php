<?php

declare(strict_types=1);

namespace app\adminapi\controller\config;

use app\adminapi\controller\BaseAdminController;
use app\adminapi\services\config\ConfigApplicationService;
use app\adminapi\validate\config\WebsiteValidate;
use app\common\http\ApiProblem;
use app\common\http\RequestTrace;
use app\common\execution\ConsumerExecutionContext;
use app\common\execution\ExecutionContextStore;
use PeanutAdmin\Kernel\Host\ApplicationHostPolicy;
use PeanutAdmin\Kernel\Tenancy\TenantEntryBindingResolver;
use PeanutAdmin\Modules\Settings\Service\WebsiteConfigService;
use PeanutAdmin\Modules\Identity\Contract\TenantIdentityQuery;
use PeanutAdmin\Modules\Identity\Policy\DemoAccountPolicy;
use think\App;
use think\facade\Config;

/** @property-read ConfigApplicationService $configuration 当前 App 中声明式解析的控制器依赖。 */
class ConfigController extends BaseAdminController
{
    protected string $configurationClass = ConfigApplicationService::class;

    public function __construct(
        App $app,
        private readonly ApplicationHostPolicy $hosts,
        private readonly TenantEntryBindingResolver $entries,
        private readonly WebsiteConfigService $website,
        private readonly TenantIdentityQuery $tenants,
        private readonly DemoAccountPolicy $demoAccounts,
        private readonly ExecutionContextStore $contexts,
    ) {
        parent::__construct($app);
    }

    public function brand()
    {
        try {
            $this->hosts->assertTenantAdmin($this->request);
        } catch (\DomainException|\InvalidArgumentException) {
            throw ApiProblem::fromEnvelope('管理入口不可用', null, 40300);
        }
        try {
            $context = $this->entries->system(
                $this->request,
                TenantEntryBindingResolver::ADMIN_CLIENT,
                'peanut.admin.public-brand',
                'decoration.config',
                RequestTrace::id($this->executionContext(), $this->request, 'admin-brand'),
            );
        } catch (\DomainException|\InvalidArgumentException) {
            throw ApiProblem::fromEnvelope('租户入口不可用', null, 50300);
        }
        $read = fn(): array => [
            'website' => $this->website->get($context),
            'tenantName' => $this->tenants->activeName($context->tenantId),
            'demo' => DemoAccountPolicy::publicLoginConfiguration((string) $this->request->host(), [
                'enabled' => $this->demoAccounts->enabled(),
                'tenant_a_host' => (string) Config::get('peanut.demo.tenant_a_host', ''),
                'tenant_b_host' => (string) Config::get('peanut.demo.tenant_b_host', ''),
                'tenant_a_email' => (string) Config::get('peanut.demo.tenant_a_email', ''),
                'tenant_b_email' => (string) Config::get('peanut.demo.tenant_b_email', ''),
                'password' => (string) Config::get('peanut.demo.shared_password', ''),
            ]),
        ];
        return $this->data($this->contexts->run(
            ConsumerExecutionContext::publicTenant($context),
            $read,
        ));
    }

    public function getWebsite()
    {
        return $this->data($this->configuration->getWebsite($this->tenantAdminContext()));
    }

    public function saveWebsite()
    {
        return $this->data($this->configuration->saveWebsite(
            $this->tenantAdminContext(),
            $this->request->post(),
        ));
    }

    public function getCopyright()
    {
        return $this->data($this->configuration->getCopyright($this->tenantAdminContext()));
    }
    public function saveCopyright()
    {
        return $this->save('copyright', 'saveCopyright');
    }
    public function getAgreement()
    {
        return $this->data($this->configuration->getAgreement($this->tenantAdminContext()));
    }
    public function saveAgreement()
    {
        return $this->save('agreement', 'saveAgreement');
    }
    public function getStatistics()
    {
        return $this->data($this->configuration->getStatistics($this->tenantAdminContext()));
    }
    public function saveStatistics()
    {
        return $this->save('statistics', 'saveStatistics');
    }
    public function getUser()
    {
        return $this->data($this->configuration->getUser($this->tenantAdminContext()));
    }
    public function saveUser()
    {
        return $this->save('user', 'saveUser');
    }
    public function getLogin()
    {
        return $this->data($this->configuration->getLogin($this->tenantAdminContext()));
    }
    public function saveLogin()
    {
        return $this->save('login', 'saveLogin');
    }

    private function save(string $scene, string $method)
    {
        $fields = match ($scene) {
            'copyright' => ['config'],
            'agreement' => ['service_title', 'service_content', 'privacy_title', 'privacy_content'],
            'statistics' => ['clarity_code'],
            'user' => ['default_avatar'],
            'login' => ['login_way', 'coerce_mobile', 'login_agreement', 'third_auth', 'wechat_auth'],
        };
        $params = $this->validateInput($this->request->post(), WebsiteValidate::class . '.' . $scene, $fields)->writable();
        $this->configuration->$method($this->tenantAdminContext(), $params);
        return $this->success('操作成功');
    }
}
