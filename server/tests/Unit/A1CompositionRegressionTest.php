<?php

declare(strict_types=1);

namespace tests\Unit\A1Composition;

use app\common\execution\AdminExecutionContext;
use app\common\execution\CurrentExecutionContext;
use app\common\execution\ExecutionContextStore;
use app\common\infrastructure\module\ModuleExecutionBoundary;
use app\common\services\CrontabCommandService;
use PeanutAdmin\Modules\Identity\Contract\AdminDirectoryQuery;
use PeanutAdmin\Modules\Integration\Contract\IntegrationSecurityRepository;
use PeanutAdmin\Modules\Integration\Webhook\WebhookDelivery;
use PeanutAdmin\Modules\Integration\Webhook\WebhookDispatcher;
use PeanutAdmin\Modules\Payment\Controller\RechargeController;
use PeanutAdmin\Modules\Payment\Service\RechargeAdministrationService;
use PeanutAdmin\Modules\Task\Contract\TaskJobService;
use PeanutAdmin\Modules\Task\Contract\TrustedJobPublisher;
use PeanutAdmin\Modules\Task\Infrastructure\Runtime\ThinkPhpTaskJobRuntime;
use PeanutAdmin\Modules\Task\Job\Persistence\TaskJobStore;
use DateTimeImmutable;
use PeanutAdmin\IntegrationSecurity\Crypto\WebhookSecretProtector;
use PeanutAdmin\IntegrationSecurity\Webhook\HostAddressResolver;
use PeanutAdmin\IntegrationSecurity\Webhook\WebhookDestinationPolicy;
use PeanutAdmin\IntegrationSecurity\Webhook\WebhookRequest;
use PeanutAdmin\IntegrationSecurity\Webhook\WebhookResponse;
use PeanutAdmin\IntegrationSecurity\Webhook\WebhookTransport;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Auth\ValidatedTenantSession;
use PeanutAdmin\Kernel\Module\ModuleRuntimeRepository;
use PHPUnit\Framework\TestCase;
use think\App;
use think\Container;
use think\Request;

/** 验证迁移后的真实类装配与 HTTP 响应合同；存储和渠道为替身，不连接 DB 或网络。 */
final class A1CompositionRegressionTest extends TestCase
{
    public function testTaskRuntimeBuildsItsPublisherUsingTheRelocatedRegistry(): void
    {
        $contexts = new ExecutionContextStore();
        $current = new CurrentExecutionContext($contexts);
        $runtime = new ThinkPhpTaskJobRuntime(
            new TaskJobStore(),
            str_repeat('fixture-', 8),
            $contexts,
            $current,
            new AdminDirectoryQuery($current),
            new ModuleExecutionBoundary($current, $this->createStub(ModuleRuntimeRepository::class), new AdminDirectoryQuery($current)),
            new CrontabCommandService([], []),
            static fn(): int => 0,
            [],
            1,
            $this->createStub(\PeanutAdmin\DataPermission\Catalog\ResourceOperationCatalog::class),
            new \PeanutAdmin\Modules\Identity\Module\TenantEntitlementService(new \PeanutAdmin\Kernel\Module\CompiledModuleRegistry([], [], [], [], 'fixture')),
        );
        self::assertInstanceOf(TrustedJobPublisher::class, $runtime->publisher());
        self::assertInstanceOf(TaskJobService::class, $runtime->jobs());
    }

    public function testWebhookDispatchUsesCoreSafetyContractsAndRequestWithoutNetwork(): void
    {
        $now = new DateTimeImmutable('2026-09-21T00:00:00Z');
        $body = '{"fixture":true}';
        $delivery = new WebhookDelivery(
            1,
            101,
            'endpoint-fixture',
            'delivery-fixture',
            'fixture.created',
            $body,
            hash('sha256', $body),
            'https://fixture.example/path',
            'fixture-ciphertext',
            'fixture-key',
            1,
            'fixture-lease',
        );
        $repository = $this->createMock(IntegrationSecurityRepository::class);
        $repository->expects(self::once())->method('claimDelivery')->with(101, self::isString(), 30, $now)->willReturn($delivery);
        $repository->expects(self::once())->method('completeDelivery')->with($delivery, 204, 1, $now);
        $repository->expects(self::never())->method('failDelivery');
        $resolver = $this->createMock(HostAddressResolver::class);
        $resolver->expects(self::once())->method('resolve')->with('fixture.example')->willReturn(['8.8.8.8']);
        $secrets = $this->createMock(WebhookSecretProtector::class);
        $secrets->expects(self::once())->method('open')->willReturn('synthetic-secret');
        $transport = $this->createMock(WebhookTransport::class);
        $transport->expects(self::once())->method('send')->willReturnCallback(static function (WebhookRequest $request) use ($body): WebhookResponse {
            self::assertSame($body, $request->body);
            self::assertFalse($request->followRedirects);
            self::assertSame('delivery-fixture', $request->headers['X-Peanut-Delivery']);
            self::assertStringStartsWith('v1=', $request->headers['X-Peanut-Signature']);
            return new WebhookResponse(204, 1);
        });
        $dispatcher = new WebhookDispatcher($repository, new WebhookDestinationPolicy($resolver), $secrets, $transport);
        self::assertTrue($dispatcher->runOne(101, $now));
    }

    public function testRechargeExportUsesTheCurrentSuccessEnvelope(): void
    {
        require_once dirname(__DIR__, 2) . '/vendor/topthink/framework/src/helper.php';
        $previous = Container::getInstance();
        $app = new App(sys_get_temp_dir() . '/peanut-a1-export-fixture-' . bin2hex(random_bytes(8)));
        $request = (new Request())->withGet(['export' => 2]);
        $app->instance(Request::class, $request);
        $contexts = new ExecutionContextStore();
        $current = new CurrentExecutionContext($contexts);
        $app->instance(CurrentExecutionContext::class, $current);
        $tenant = TenantContext::fromValidatedSession(new ValidatedTenantSession(
            301,
            'fixture-session',
            101,
            201,
            301,
            'admin-web',
            new DateTimeImmutable('2031-01-01T00:00:00Z'),
            1,
            1,
        ), 'fixture-export');
        $result = ['url' => '/fixture/download', 'file_name' => 'fixture.xlsx'];
        $service = $this->createMock(RechargeAdministrationService::class);
        $service->expects(self::once())->method('lists')->with($tenant, ['export' => 2])->willReturn($result);
        $app->instance(RechargeAdministrationService::class, $service);
        try {
            $response = $contexts->run(
                new AdminExecutionContext($tenant, 'fixture.export', ['id' => 301]),
                static fn() => (new RechargeController($app))->lists(),
            );
            self::assertSame(200, $response->getCode());
            self::assertSame(['code' => 20000, 'msg' => '', 'data' => $result], $response->getData());
            self::assertTrue($contexts->isEmpty());
        } finally {
            Container::setInstance($previous);
        }
    }
}
