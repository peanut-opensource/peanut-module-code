<?php

declare(strict_types=1);

require dirname(__DIR__, 4) . '/vendor/autoload.php';

use app\common\composition\ModuleComposition;
use app\common\services\authorization\AdminAuthorizationService;
use PeanutAdmin\Kernel\Async\VerifiedJobEnvelope;
use PeanutAdmin\Kernel\Context\AuthorizedOperationContext;
use PeanutAdmin\Kernel\Module\CompiledModuleRegistry;
use PeanutAdmin\Kernel\Module\ManifestDocument;
use PeanutAdmin\Kernel\Module\ModuleException;
use PeanutAdmin\Kernel\Module\ModuleProvider;
use PeanutAdmin\Modules\ImportExport\ModuleProvider as ImportExportModuleProvider;
use PeanutAdmin\Modules\Notification\ModuleProvider as NotificationModuleProvider;
use PeanutAdmin\Modules\Notification\Delivery\Task\NotificationTaskWorkerDefinition;
use PeanutAdmin\Modules\Notification\Delivery\Persistence\NotificationRepository;
use PeanutAdmin\Modules\Notification\Delivery\Persistence\NotificationStore;
use PeanutAdmin\Modules\Notification\Delivery\Sms\DisabledSmsProvider;
use PeanutAdmin\Modules\Notification\Delivery\Sms\SmsProvider;
use PeanutAdmin\Modules\ImportExport\Service\ImportExportTaskWorkerDefinition;
use PeanutAdmin\Modules\Task\Contract\JobExecution;
use PeanutAdmin\Modules\Task\Contract\TaskHandler;
use PeanutAdmin\Modules\Task\Contract\TaskWorkerContributor;
use PeanutAdmin\Modules\Task\Contract\TaskWorkerDefinition;
use PeanutAdmin\Modules\Task\Service\TaskWorkerDefinitionRegistry;
use think\App;

function workerExpect(bool $condition, string $message): void
{
    $GLOBALS['taskWorkerCompositionChecks'] = ($GLOBALS['taskWorkerCompositionChecks'] ?? 0) + 1;
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

final class FixtureWorkerHandler implements TaskHandler
{
    public function key(): string
    {
        return 'fixture.worker.handle';
    }

    public function handle(AuthorizedOperationContext $context, JobExecution $execution): void {}
}

final class FixtureWorkerDefinition implements TaskWorkerDefinition
{
    public function ownerModuleKey(): string
    {
        return 'fixture.worker';
    }

    public function resourceKey(): string
    {
        return 'fixture.worker.resource';
    }

    public function operation(): string
    {
        return 'execute';
    }

    public function handlers(): array
    {
        return [new FixtureWorkerHandler()];
    }

    public function reauthorize(VerifiedJobEnvelope $envelope): AuthorizedOperationContext
    {
        throw new LogicException('not used by composition harness');
    }
}

final class FixtureWorkerProvider implements ModuleProvider, TaskWorkerContributor
{
    public function moduleKey(): string
    {
        return 'fixture.worker';
    }

    public function bindings(): array
    {
        return [];
    }

    public function taskWorkerDefinitions(): array
    {
        return [FixtureWorkerDefinition::class];
    }
}

$registry = new CompiledModuleRegistry([
    ManifestDocument::fromObject('/fixture/worker', (object) [
        'key' => 'fixture.worker',
        'backend' => ['provider' => FixtureWorkerProvider::class],
    ]),
], [], [], [], 'test');
$app = new App(dirname(__DIR__, 4));
(new ModuleComposition($app))->register($registry);
$definitions = $app->make(TaskWorkerDefinitionRegistry::class)->resolve($app);
workerExpect(count($definitions) === 1, 'compiled worker contribution missing');
workerExpect($definitions[0] instanceof FixtureWorkerDefinition, 'compiled worker definition type changed');
workerExpect(count([...$definitions[0]->handlers()]) === 1, 'compiled worker handler missing');

$importDefinitions = (new ImportExportModuleProvider($app))->taskWorkerDefinitions();
$notificationDefinitions = (new NotificationModuleProvider())->taskWorkerDefinitions();
workerExpect($importDefinitions === [ImportExportTaskWorkerDefinition::class], 'Import/Export worker declaration changed');
workerExpect($notificationDefinitions === [NotificationTaskWorkerDefinition::class], 'Notification worker declaration changed');

$notificationApp = new App(dirname(__DIR__, 4));
(new ModuleComposition($notificationApp))->register(new CompiledModuleRegistry([
    ManifestDocument::fromObject('/peanut/notification', (object) [
        'key' => 'peanut.notification',
        'backend' => ['provider' => NotificationModuleProvider::class],
    ]),
], [], [], [], 'test'));
$notificationApp->instance(
    NotificationStore::class,
    (new ReflectionClass(NotificationStore::class))->newInstanceWithoutConstructor(),
);
$notificationApp->instance(
    \PeanutAdmin\Modules\Notification\Infrastructure\Authorization\NotificationAsyncAuthorization::class,
    (new ReflectionClass(\PeanutAdmin\Modules\Notification\Infrastructure\Authorization\NotificationAsyncAuthorization::class))->newInstanceWithoutConstructor(),
);
$notificationApp->instance(
    AdminAuthorizationService::class,
    (new ReflectionClass(AdminAuthorizationService::class))->newInstanceWithoutConstructor(),
);
$notificationWorker = $notificationApp->make(TaskWorkerDefinitionRegistry::class)->resolve($notificationApp)[0] ?? null;
workerExpect($notificationWorker instanceof NotificationTaskWorkerDefinition, 'Notification worker did not resolve through Module composition');
$handlerKeys = array_map(static fn(TaskHandler $handler): string => $handler->key(), [...$notificationWorker->handlers()]);
sort($handlerKeys, SORT_STRING);
workerExpect($handlerKeys === ['notification.inbox', 'notification.sms'], 'Notification handlers are incomplete');
workerExpect($notificationApp->make(SmsProvider::class) instanceof DisabledSmsProvider, 'non-development SMS provider did not fail closed');

try {
    new TaskWorkerDefinitionRegistry([
        ['module_key' => 'fixture.worker', 'definition' => FixtureWorkerDefinition::class],
        ['module_key' => 'fixture.worker', 'definition' => FixtureWorkerDefinition::class],
    ]);
    throw new RuntimeException('duplicate worker definition was accepted');
} catch (ModuleException $exception) {
    workerExpect($exception->errorCode === 'MODULE_TASK_WORKER_INVALID', 'duplicate worker error changed');
}

try {
    (new TaskWorkerDefinitionRegistry([
        ['module_key' => 'fixture.other', 'definition' => FixtureWorkerDefinition::class],
    ]))->resolve(new App(dirname(__DIR__, 4)));
    throw new RuntimeException('worker owner mismatch was accepted');
} catch (ModuleException $exception) {
    workerExpect($exception->errorCode === 'MODULE_TASK_WORKER_INVALID', 'worker owner mismatch error changed');
}

fwrite(STDOUT, 'task worker composition harness: PASS (' . ($GLOBALS['taskWorkerCompositionChecks'] ?? 0) . " checks)\n");
