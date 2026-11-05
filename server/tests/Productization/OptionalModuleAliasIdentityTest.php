<?php

declare(strict_types=1);

use app\common\composition\ModuleComposition;
use app\common\composition\ModuleExtensionService;
use Composer\Autoload\ClassLoader;
use PeanutAdmin\Kernel\Module\CompiledModuleRegistry;
use PeanutAdmin\Kernel\Module\ManifestDocument;
use PeanutAdmin\Modules\Article\Contract\ArticleQueries;
use PeanutAdmin\Modules\Article\Service\ArticleQueryService;
use PHPUnit\Framework\TestCase;
use think\App;

/** Real Module providers and ThinkPHP aliases; constructor leaves never perform I/O. */
final class OptionalModuleAliasIdentityTest extends TestCase
{
    private static ClassLoader $loader;

    public static function setUpBeforeClass(): void
    {
        self::$loader = new ClassLoader();
        foreach (glob(dirname(__DIR__, 2) . '/app/modules/peanut/*/composer.json') as $path) {
            $composer = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
            foreach ($composer['autoload']['psr-4'] as $prefix => $paths) {
                self::$loader->addPsr4($prefix, array_map(static fn($source) => dirname($path) . '/' . $source, (array) $paths));
            }
        }
        self::$loader->register(true);
    }

    public static function tearDownAfterClass(): void
    {
        self::$loader->unregister();
    }

    public function testArticleQueryAliasPreservesObjectIdentity(): void
    {
        $app = $this->compose();
        self::assertSame($app->make(ArticleQueries::class), $app->make(ArticleQueryService::class));
    }

    public function testOptionalPublicContractsAutowireActualProductionServices(): void
    {
        $targets = [
            \PeanutAdmin\Modules\Article\Contract\PublicArticleQueries::class => \PeanutAdmin\Modules\Article\Service\PublicArticleService::class,
            \PeanutAdmin\Modules\Article\Contract\ArticleAdministration::class => \PeanutAdmin\Modules\Article\Service\ArticleAdministrationService::class,
            \PeanutAdmin\Modules\Article\Contract\ArticleCategoryAdministration::class => \PeanutAdmin\Modules\Article\Service\ArticleCategoryAdministrationService::class,
            \PeanutAdmin\Modules\Member\Contract\MemberQueries::class => \PeanutAdmin\Modules\Member\Service\MemberQueryService::class,
            \PeanutAdmin\Modules\Member\Contract\MemberSubjectLookup::class => \PeanutAdmin\Modules\Member\Infrastructure\Persistence\ThinkPhpMemberSubjectLookup::class,
            \PeanutAdmin\Modules\Member\Contract\MemberAdministration::class => \PeanutAdmin\Modules\Member\Service\MemberAdministrationService::class,
            \PeanutAdmin\Modules\ImportExport\Service\TenantConfigurationTransferService::class => \PeanutAdmin\Modules\ImportExport\Service\TenantConfigurationTransferService::class,
            \PeanutAdmin\Modules\ImportExport\Infrastructure\File\AppFileMediaGateway::class => \PeanutAdmin\Modules\ImportExport\Infrastructure\File\AppFileMediaGateway::class,
            \PeanutAdmin\Modules\ImportExport\Contract\ImportExportWorkerRuntime::class => \PeanutAdmin\Modules\ImportExport\Service\TaskImportExportRuntime::class,
            \PeanutAdmin\Modules\ImportExport\Service\OperationLogExportApplicationService::class => \PeanutAdmin\Modules\ImportExport\Service\OperationLogExportApplicationService::class,
            \app\common\services\notice\NoticeChannelService::class => \app\common\services\notice\NoticeChannelService::class,
            \PeanutAdmin\Modules\Notification\Contract\NotificationCommands::class => \PeanutAdmin\Modules\Notification\Service\NotificationApplicationService::class,
            \app\common\composition\payment\PaymentServiceFactory::class => \app\common\composition\payment\PaymentServiceFactory::class,
            \PeanutAdmin\Modules\Payment\Contract\PaymentChannelGrantCommands::class => \PeanutAdmin\Modules\Payment\Infrastructure\ThinkPhpPaymentChannelGrantCommands::class,
        ];
        foreach ($targets as $abstract => $concrete) {
            $app = $this->compose();
            $resolved = $this->makeWithControlledLeaves($app, $abstract, $concrete);
            self::assertInstanceOf($concrete, $resolved, $abstract);
            self::assertSame($resolved, $app->make($concrete), $abstract);
        }
    }

    public function testCommandQueryAliasesShareTheirProductionImplementation(): void
    {
        foreach ([
            [\PeanutAdmin\Modules\ImportExport\Contract\ImportExportCommands::class, \PeanutAdmin\Modules\ImportExport\Contract\ImportExportQueries::class, \PeanutAdmin\Modules\ImportExport\Service\ImportExportApplicationService::class],
            [\PeanutAdmin\Modules\ImportExport\Contract\ConfigurationTransferCommands::class, \PeanutAdmin\Modules\ImportExport\Contract\ConfigurationTransferQueries::class, \PeanutAdmin\Modules\ImportExport\Service\ConfigurationTransferApplicationService::class],
            [\PeanutAdmin\Modules\Notification\Contract\NotificationCommands::class, \PeanutAdmin\Modules\Notification\Contract\NotificationQueries::class, \PeanutAdmin\Modules\Notification\Service\NotificationApplicationService::class],
        ] as [$commands, $queries, $concrete]) {
            $app = $this->compose();
            if ($concrete !== \PeanutAdmin\Modules\Notification\Service\NotificationApplicationService::class) {
                // These existing provider factories assemble runtime jobs or transfer adapters.
                // As in the source test, only their native public aliases are under test here.
                $app->instance($concrete, (new ReflectionClass($concrete))->newInstanceWithoutConstructor());
                $resolved = $app->make($commands);
            } else {
                $resolved = $this->makeWithControlledLeaves($app, $commands, $concrete);
            }
            self::assertInstanceOf($concrete, $resolved);
            self::assertSame($resolved, $app->make($queries), $commands);
            self::assertSame($resolved, $app->make($concrete), $commands);
        }
    }

    private function compose(): App
    {
        $server = dirname(__DIR__, 2);
        $manifests = [];
        foreach (glob($server . '/app/modules/peanut/*/module.json') as $path) {
            $manifests[] = ManifestDocument::fromObject(dirname($path), json_decode(file_get_contents($path), false, 512, JSON_THROW_ON_ERROR));
        }
        $registry = new CompiledModuleRegistry($manifests, [], [], [], 'optional-alias-identity');
        self::assertCount(22, $registry->modules);
        $app = new App($server);
        (new ReflectionProperty(App::class, 'initialized'))->setValue($app, true);
        $app->bind(require $server . '/app/adminapi/provider.php');
        $app->instance(CompiledModuleRegistry::class, $registry);
        (new ModuleComposition($app))->register($registry);
        (new ModuleExtensionService($app))->register();
        return $app;
    }

    /** Resolve the service itself natively, retaining the original constructor-only test boundary. */
    private function makeWithControlledLeaves(App $app, string $abstract, string $concrete): object
    {
        $implementations = [
            \app\common\contract\idempotency\IdempotentCommandExecutor::class => \app\common\infrastructure\idempotency\ThinkPhpIdempotentCommandExecutor::class,
            \PeanutAdmin\Modules\Task\Contract\TaskJobRuntime::class => \PeanutAdmin\Modules\Task\Infrastructure\Runtime\ThinkPhpTaskJobRuntime::class,
            \app\common\contract\http\OutboundHttpTransport::class => \app\common\infrastructure\http\GuzzleOutboundHttpTransport::class,
            \app\common\contract\payment\PaymentTransportInterface::class => \app\common\infrastructure\payment\CurlPaymentTransport::class,
        ];
        foreach ((new ReflectionClass($concrete))->getConstructor()?->getParameters() ?? [] as $parameter) {
            $type = $parameter->getType();
            if (!$type instanceof ReflectionNamedType || $type->isBuiltin()) {
                continue;
            }
            $dependency = $type->getName();
            $implementation = $implementations[$dependency] ?? $app->getAlias($dependency);
            if (!class_exists($implementation)) {
                throw new RuntimeException('Production dependency has no concrete implementation: ' . $dependency);
            }
            $app->instance($dependency, (new ReflectionClass($implementation))->newInstanceWithoutConstructor());
        }
        return $app->make($abstract);
    }
}
