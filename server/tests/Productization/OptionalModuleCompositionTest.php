<?php

declare(strict_types=1);

use app\common\composition\ModuleComposition;
use app\common\composition\ModuleConsoleCommands;
use app\common\composition\ModuleExtensionService;
use app\common\services\CrontabCommandService;
use Composer\Autoload\ClassLoader;
use PeanutAdmin\Kernel\Module\CompiledModuleRegistry;
use PeanutAdmin\Kernel\Module\ManifestDocument;
use PeanutAdmin\Kernel\Module\ModuleException;
use PeanutAdmin\Kernel\Module\ModuleProvider;
use PHPUnit\Framework\TestCase;
use think\App;

/** Actual selected sources and native services; no database, handlers or external channels. */
final class OptionalModuleCompositionTest extends TestCase
{
    private static ClassLoader $loader;
    private static string $application;
    private static string $modules;
    private const BASE = ['content_tools', 'file', 'identity', 'integration', 'ops', 'reference_codes', 'settings', 'task'];

    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 2) . '/vendor/topthink/framework/src/helper.php';
        self::$application = rtrim((string) getenv('PEANUT_COMPOSITION_APPLICATION_ROOT'), '/');
        self::$modules = rtrim((string) getenv('PEANUT_OPTIONAL_MODULE_APPLICATION_ROOT'), '/');
        foreach ([self::$application, self::$modules] as $root) {
            if (!is_file($root . '/server/app/AppService.php')) {
                throw new RuntimeException('Explicit actual application source roots are required.');
            }
        }
        self::$loader = new ClassLoader();
        self::$loader->addPsr4('app\\', self::$application . '/server/app');
        foreach (glob(self::$modules . '/server/app/modules/*/*/composer.json') as $path) {
            $local = self::$application . '/server/app/modules/peanut/' . basename(dirname($path));
            $root = is_file($local . '/composer.json') ? $local : dirname($path);
            $composer = json_decode(file_get_contents($root . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
            foreach ($composer['autoload']['psr-4'] as $prefix => $paths) {
                self::$loader->addPsr4($prefix, array_map(static fn($path) => $root . '/' . $path, (array) $paths));
            }
        }
        self::$loader->register(true);
    }

    public static function tearDownAfterClass(): void
    {
        self::$loader->unregister();
    }

    public function testEightModuleSelectionDoesNotRegisterOptionalCommands(): void
    {
        [$app, $registry] = $this->compose(false);
        self::assertCount(8, $registry->modules);
        $console = $app->make('console');
        foreach (['refund:reconcile', 'workflow:install-managed', 'import-export:expire'] as $name) {
            self::assertFalse($console->hasCommand($name));
        }
        self::assertSame([], $app->make(ModuleConsoleCommands::class)->contributions());
        self::assertSame([], $app->config->get('console.commands'));
        self::assertSame([], $app->config->get('console.module_commands'));
        self::assertFalse($app->bound(\PeanutAdmin\Modules\Workflow\Integration\WorkflowScenarioRegistry::class));
        self::assertFalse($app->bound(\PeanutAdmin\Modules\Notification\Contract\NotificationProducerRegistry::class));
    }

    public function testAllTwentyThreeModulesRegisterActualCommandsAndOwnedRegistries(): void
    {
        [$app, $registry] = $this->compose(true);
        self::assertCount(23, $registry->modules);
        $commands = $app->make(ModuleConsoleCommands::class)->contributions();
        $console = $app->make('console');
        self::assertSame($console, $app->make('console'));
        self::assertTrue($console->hasCommand('route:list'));
        self::assertSame([], $app->config->get('console.commands'));
        self::assertCount(3, $commands);
        foreach ($commands as $name => $class) {
            self::assertTrue($console->hasCommand($name));
            self::assertSame($name, (new $class())->getName());
            self::assertStringContainsString('/modules/peanut/', (new ReflectionClass($class))->getFileName());
        }
        self::assertSame(['refund:reconcile' => 'peanut.payment'], $app->config->get('console.module_commands'));
        $scheduler = new CrontabCommandService($commands, $app->config->get('console.module_commands'));
        $scheduler->assertTenantAware('refund:reconcile');
        foreach (['workflow:install-managed', 'import-export:expire'] as $name) {
            try {
                $scheduler->assertTenantAware($name);
                self::fail('CLI command acquired Tenant scheduler authority.');
            } catch (RuntimeException $error) {
                self::assertStringContainsString('可信租户上下文', $error->getMessage());
            }
        }
        self::assertInstanceOf(\PeanutAdmin\Modules\Workflow\Integration\WorkflowScenarioRegistry::class, $app->make(\PeanutAdmin\Modules\Workflow\Integration\WorkflowScenarioRegistry::class));
        self::assertSame(\PeanutAdmin\Modules\Workflow\Integration\FileWorkflowAttachmentResolver::class, $app->getAlias(\PeanutAdmin\Modules\Workflow\Adapter\WorkflowAttachmentResolver::class));
        self::assertInstanceOf(\PeanutAdmin\Modules\Notification\Contract\NotificationProducerRegistry::class, $app->make(\PeanutAdmin\Modules\Notification\Contract\NotificationProducerRegistry::class));
        self::assertTrue($app->bound(\PeanutAdmin\Modules\Identity\Contract\RevocationFactDelivery::class));
        $workers = $app->make(\PeanutAdmin\Modules\Task\Service\TaskWorkerDefinitionRegistry::class);
        $rows = (new ReflectionProperty($workers, 'registrations'))->getValue($workers);
        self::assertContains('peanut.collaboration', array_column($rows, 'module_key'));
        self::assertContains('peanut.notification', array_column($rows, 'module_key'));
        self::assertContains('peanut.import-export', array_column($rows, 'module_key'));
    }

    public function testCommandConflictLeavesConfigurationUnchanged(): void
    {
        [$app] = $this->compose(true);
        $before = $app->config->get('console');
        $commands = $app->make(ModuleConsoleCommands::class)->contributions();
        try {
            ModuleConsoleCommands::register($app, 'peanut.payment', ['refund:reconcile' => \PeanutAdmin\Modules\Payment\Console\RefundReconcile::class]);
            self::fail('Duplicate Module command accepted.');
        } catch (ModuleException $error) {
            self::assertSame('MODULE_COMMAND_CONFLICT', $error->errorCode);
        }
        self::assertSame($before, $app->config->get('console'));
        self::assertSame($commands, $app->make(ModuleConsoleCommands::class)->contributions());

        $callbacks = new ReflectionProperty(\think\Console::class, 'startCallbacks');
        $original = $callbacks->getValue();
        try {
            // Use the real migration Service, whose commands are added after config.
            (new \think\migration\Service($app))->boot();
            \think\Console::starting(static fn(\think\Console $console) => $console->addCommand(OptionalNativeAliasCommand::class));
            foreach ([
                'route:list' => \think\console\command\RouteList::class,
                'migrate:run' => \think\migration\command\migrate\Run::class,
                'module:compile' => \app\command\ModuleCompile::class,
                'fixture:alias' => OptionalModuleAliasCommand::class,
            ] as $name => $command) {
                [$host] = $this->compose(false);
                $native = ['module:compile' => \app\command\ModuleCompile::class];
                $host->config->set(['commands' => $native], 'console');
                ModuleConsoleCommands::register($host, 'peanut.identity', [$name => $command]);
                try {
                    $host->make('console');
                    self::fail("Native duplicate command accepted: {$name}");
                } catch (ModuleException $error) {
                    self::assertSame('MODULE_COMMAND_CONFLICT', $error->errorCode);
                    self::assertStringContainsString($name, $error->getMessage());
                }
                self::assertSame($native, $host->config->get('console.commands'));
            }
        } finally {
            $callbacks->setValue(null, $original);
        }
    }

    public function testInvalidCommandAndWrongApprovedOwnerFailClosed(): void
    {
        foreach ([['fixture:invalid' => stdClass::class], ['wrong:name' => \PeanutAdmin\Modules\Payment\Console\RefundReconcile::class], ['refund:reconcile' => \PeanutAdmin\Modules\Payment\Console\RefundReconcile::class]] as $commands) {
            [$app] = $this->compose(false);
            try {
                ModuleConsoleCommands::register($app, 'peanut.identity', $commands);
                self::fail('Invalid command accepted.');
            } catch (ModuleException $error) {
                self::assertSame('MODULE_COMMAND_INVALID', $error->errorCode);
            }
            self::assertSame([], $app->config->get('console.commands'));
            self::assertSame([], $app->make(ModuleConsoleCommands::class)->contributions());
            self::assertSame([], $app->config->get('console.module_commands'));
        }
        $this->expectException(RuntimeException::class);
        (new CrontabCommandService(['refund:reconcile' => 'fixture'], ['refund:reconcile' => 'peanut.identity']))->assertTenantAware('refund:reconcile');
    }

    public function testInvalidOptionalWorkerFailsBeforeBindingsArePublished(): void
    {
        $app = new App(self::$application . '/server');
        $manifest = ManifestDocument::fromObject('/fixture', (object) ['key' => 'fixture.invalid', 'backend' => ['provider' => OptionalInvalidWorkerProvider::class]]);
        $task = ManifestDocument::fromObject('/task', (object) ['key' => 'peanut.task', 'backend' => ['provider' => \PeanutAdmin\Modules\Task\ModuleProvider::class]]);
        try {
            (new ModuleComposition($app))->register(new CompiledModuleRegistry([$task, $manifest], [], [], [], 'invalid'));
            self::fail('Invalid optional worker accepted.');
        } catch (ModuleException $error) {
            self::assertSame('MODULE_TASK_WORKER_INVALID', $error->errorCode);
        }
        self::assertFalse($app->bound(OptionalInvalidWorkerProvider::class));
        self::assertFalse($app->bound(\PeanutAdmin\Modules\Task\Contract\TaskJobRuntime::class));
    }

    public function testMovedCommandsKeepTheirExecutionIdentityAndDenyUntrustedInvocation(): void
    {
        $contexts = new \app\common\execution\ExecutionContextStore();
        $current = new \app\common\execution\CurrentExecutionContext($contexts);
        $refund = new \PeanutAdmin\Modules\Payment\Console\RefundReconcile($contexts, $current);
        try {
            $refund->run(new \think\console\Input([]), new \think\console\Output());
            self::fail('Refund command ran without a scheduled Tenant scope.');
        } catch (RuntimeException $error) {
            self::assertSame('Scheduled TenantContext is required', $error->getMessage());
        }
        self::assertTrue($contexts->isEmpty());
        $expiry = new \PeanutAdmin\Modules\ImportExport\Console\ExpireOperationsCommand(null, $contexts, $current);
        try {
            $contexts->run(new \app\common\execution\InstanceExecutionContext('fixture', 'fixture'), fn() => $expiry->run(new \think\console\Input([]), new \think\console\Output()));
            self::fail('Expiry command accepted an inherited execution identity.');
        } catch (DomainException $error) {
            self::assertSame('PLATFORM_MAINTENANCE_CONTEXT_REQUIRED', $error->getMessage());
        }
        self::assertTrue($contexts->isEmpty());
        $workflow = new \PeanutAdmin\Modules\Workflow\Console\WorkflowInstallManaged($contexts, $current);
        self::assertFalse((new ReflectionMethod($workflow, 'establishesInstanceContext'))->invoke($workflow));
    }

    public function testReservedRegistryConflictFailsBeforeContainerMutation(): void
    {
        $app = new App(self::$application . '/server');
        $manifest = ManifestDocument::fromObject('/fixture', (object) ['key' => 'fixture.conflict', 'backend' => ['provider' => OptionalConflictingRegistryProvider::class]]);
        try {
            (new ModuleComposition($app))->register(new CompiledModuleRegistry([$manifest], [], [], [], 'conflict'));
            self::fail('Reserved registry was overwritten.');
        } catch (ModuleException $error) {
            self::assertSame('MODULE_BINDING_CONFLICT', $error->errorCode);
        }
        self::assertFalse($app->bound(\PeanutAdmin\Modules\Task\Service\TaskWorkerDefinitionRegistry::class));
        self::assertFalse($app->bound(OptionalConflictingRegistryProvider::class));
    }

    /** @return array{App, CompiledModuleRegistry} */
    private function compose(bool $optional): array
    {
        $manifests = [];
        foreach (glob(self::$modules . '/server/app/modules/*/*/module.json') as $path) {
            $directory = basename(dirname($path));
            if (!$optional && !in_array($directory, self::BASE, true)) {
                continue;
            }
            $local = self::$application . '/server/app/modules/peanut/' . $directory . '/module.json';
            $path = is_file($local) ? $local : $path;
            $manifests[] = ManifestDocument::fromObject(dirname($path), json_decode(file_get_contents($path), false, 512, JSON_THROW_ON_ERROR));
        }
        $registry = new CompiledModuleRegistry($manifests, [], [], [], 'optional-composition');
        $app = new App(self::$application . '/server');
        // Composition here is explicit; the real Console still runs its native construction callbacks.
        (new ReflectionProperty(App::class, 'initialized'))->setValue($app, true);
        $app->config->set(['commands' => [], 'module_commands' => []], 'console');
        $app->instance(CompiledModuleRegistry::class, $registry);
        (new ModuleComposition($app))->register($registry);
        (new ModuleExtensionService($app))->register();
        return [$app, $registry];
    }
}

final class OptionalInvalidWorkerProvider implements ModuleProvider
{
    public function moduleKey(): string { return 'fixture.invalid'; }
    public function bindings(): array { return []; }
    public function taskWorkerDefinitions(): array { return [stdClass::class]; }
}

final class OptionalConflictingRegistryProvider implements ModuleProvider
{
    public function moduleKey(): string { return 'fixture.conflict'; }
    public function bindings(): array
    {
        return [\PeanutAdmin\Modules\Task\Service\TaskWorkerDefinitionRegistry::class => static fn() => new \PeanutAdmin\Modules\Task\Service\TaskWorkerDefinitionRegistry([])];
    }
}

final class OptionalNativeAliasCommand extends \think\console\Command
{
    protected function configure()
    {
        $this->setName('fixture:native')->setAliases(['fixture:alias']);
    }
}

final class OptionalModuleAliasCommand extends \think\console\Command
{
    protected function configure()
    {
        $this->setName('fixture:alias');
    }
}
