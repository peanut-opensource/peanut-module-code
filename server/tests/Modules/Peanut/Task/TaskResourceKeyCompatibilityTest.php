<?php

declare(strict_types=1);

use PeanutAdmin\DataPermission\Catalog\ResourceOperationCatalog;
use PeanutAdmin\Kernel\Auth\AuthException;
use PeanutAdmin\Modules\Task\Job\Application\TaskJobException;
use PeanutAdmin\Modules\Task\Job\Submission\TaskSubmissionRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use TaskResourceKeyCompatibilityFixture\Fixture;
use TaskResourceKeyCompatibilityFixture\Provider;
use think\facade\Db;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class TaskResourceKeyCompatibilityTest extends TestCase
{
    protected function setUp(): void
    {
        $server = dirname(__DIR__, 4);
        require_once $server . '/vendor/autoload.php';
        require_once $server . '/vendor/topthink/framework/src/helper.php';
        require_once __DIR__ . '/TaskResourceKeyCompatibilitySupport.php';
    }

    public function testNativePublisherAndWorkerPreserveCanonicalCamelCaseResource(): void
    {
        $server = dirname(__DIR__, 4);
        $schema = json_decode(file_get_contents($server . '/vendor/peanut-admin/core/kernel/resources/schemas/module-manifest.schema.json'), false, 64, JSON_THROW_ON_ERROR);
        $keySchema = $schema->properties->catalog->properties->protected_resources->items->properties->key;
        self::assertTrue((new Opis\JsonSchema\Validator())->validate(Provider::RESOURCE, $keySchema)->isValid());
        $provider = new Provider();
        // Original production code throws TASK_REQUEST_INVALID at this exact registration.
        $registry = new TaskSubmissionRegistry([$provider]);
        self::assertSame($provider, $registry->require($provider->taskType()));
        $fixture = new Fixture();
        self::assertSame([Provider::MODULE], $fixture->entitlements->moduleKeys(1));
        $publisher = $fixture->publisher($this->catalog(), $provider);
        $publisher->assertRegistered($provider->taskType(), Provider::RESOURCE, 'execute');
        $job = $publisher->publish(
            TaskResourceKeyCompatibilityFixture\context(Provider::RESOURCE),
            $provider->taskType(),
            ['order_id' => 71],
            'task-key-positive-007',
        );
        self::assertSame('queued', $job->status);
        $row = $fixture->row($job->jobKey);
        $envelope = $fixture->codec->verify($row['trusted_envelope']);
        self::assertSame(Provider::RESOURCE, $envelope->resourceKey);
        self::assertSame(Provider::RESOURCE, $envelope->requestedTargets[0]->targetResourceKey);
        self::assertSame($job->jobKey, $envelope->operationId);
        $event = Db::name('task_job_event')->where('job_id', $row['id'])->where('event_key', 'tenant.task.submitted')->find();
        self::assertSame(Provider::RESOURCE, json_decode($event['metadata_json'], true, 32, JSON_THROW_ON_ERROR)['producer_resource']);
        $beforeReplay = $fixture->state();
        self::assertSame($job->jobKey, $publisher->publish(
            TaskResourceKeyCompatibilityFixture\context(Provider::RESOURCE),
            $provider->taskType(),
            ['order_id' => 71],
            'task-key-positive-007',
        )->jobKey);
        self::assertSame($beforeReplay, $fixture->state());
        self::assertSame('succeeded', $fixture->worker($provider, $job->jobKey)->runOnce());
        self::assertCount(1, $provider->handled);
        self::assertSame(Provider::RESOURCE, $provider->handled[0]['resource']);
        self::assertSame(Provider::RESOURCE, $provider->handled[0]['targets'][0]['target_resource_key']);
        self::assertSame(['order_id' => 71], $provider->handled[0]['payload']);
        self::assertGreaterThanOrEqual(2, $provider->authorizationCalls);
        self::assertSame($row['trusted_envelope'], $fixture->row($job->jobKey)['trusted_envelope']);
        self::assertSame('succeeded', $fixture->store->get(1, $job->jobKey)->status);
        self::assertNull($fixture->worker($provider, $job->jobKey)->runOnce());
        self::assertCount(1, $provider->handled);
    }

    #[DataProvider('legalKeys')]
    public function testRegistrationPreservesLegalCase(string $key): void
    {
        $provider = new Provider($key);
        $registry = new TaskSubmissionRegistry([$provider]);
        self::assertSame($key, $registry->require($provider->taskType())->resourceKey());
    }

    public static function legalKeys(): iterable
    {
        yield 'existing lowercase' => ['fixture.resource-key.record'];
        yield 'actual BusinessOps resource' => ['custom.business-ops.serviceOrder'];
        yield 'capitalized child' => ['custom.business-ops.ServiceOrder'];
        yield 'nested mixed case' => ['custom.business-ops.serviceOrder.lineItem2'];
    }

    #[DataProvider('invalidDescriptors')]
    public function testInvalidFormatsRemainRejected(string $resource, string $type, string $operation): void
    {
        self::rejectTask(fn() => new TaskSubmissionRegistry([new Provider($resource, $type, $operation)]), 'TASK_REQUEST_INVALID', 422);
    }

    public static function invalidDescriptors(): iterable
    {
        foreach (['', ' fixture.record', 'fixture.record ', "fixture.record\n", "fixture.record\0",
            'fixture..record', 'fixture.-record', 'fixture.record-', 'fixture.record/item', 'fixture_record', '1fixture.record'] as $i => $key) {
            yield 'resource-' . $i => [$key, 'fixture.task', 'execute'];
        }
        foreach (['', 'Fixture.task', 'fixture.Task', 'fixture.task_name', 'fixture..task', 'fixture.task-', 'fixture/task', "fixture.task\n"] as $i => $key) {
            yield 'type-' . $i => ['fixture.record', $key, 'execute'];
        }
        foreach (['', 'Execute', 'executeNow', 'execute_now', 'execute..now', 'execute-', 'execute/now', "execute\n"] as $i => $key) {
            yield 'operation-' . $i => ['fixture.record', 'fixture.task', $key];
        }
    }

    public function testDuplicateTaskTypeAndMissingTypeRemainRejected(): void
    {
        $provider = new Provider();
        $registry = new TaskSubmissionRegistry([$provider]);
        self::rejectTask(fn() => $registry->register(new Provider(strtolower(Provider::RESOURCE))), 'TASK_REQUEST_INVALID', 422);
        self::assertSame($provider, $registry->require($provider->taskType()));
        self::rejectTask(fn() => $registry->require('fixture.missing'), 'TASK_REQUEST_INVALID', 422);
    }

    public function testWrongCaseCannotPublishOrPassDescriptorCheck(): void
    {
        $fixture = new Fixture();
        $provider = new Provider();
        $publisher = $fixture->publisher($this->catalog(), $provider);
        $before = $fixture->state();
        self::rejectTask(fn() => $publisher->assertRegistered($provider->taskType(), strtolower(Provider::RESOURCE), 'execute'), 'TASK_PERMISSION_DENIED', 403);
        self::rejectTask(fn() => $publisher->publish(
            TaskResourceKeyCompatibilityFixture\context(strtolower(Provider::RESOURCE)),
            $provider->taskType(),
            ['order_id' => 71],
            'task-key-wrong-case-007',
        ), 'TASK_PERMISSION_DENIED', 403);
        self::assertSame(0, $provider->buildCalls);
        self::assertSame($before, $fixture->state());
    }

    public function testCurrentAuthorizationCannotReplaceSignedResourceCase(): void
    {
        [$fixture, $provider, $job] = $this->queued();
        $provider->currentResource = strtolower(Provider::RESOURCE);
        self::assertSame('dead', $fixture->worker($provider, $job->jobKey)->runOnce());
        self::assertSame(1, $provider->authorizationCalls);
        self::assertSame([], $provider->handled);
        self::assertSame('TASK_PERMISSION_DENIED', $fixture->store->get(1, $job->jobKey)->lastErrorCode);
    }

    public function testValidlySignedWrongCaseDoesNotSelectOriginalWorker(): void
    {
        [$fixture, $provider, $job] = $this->queued();
        $encoded = $fixture->codec->issue(
            TaskResourceKeyCompatibilityFixture\context(strtolower(Provider::RESOURCE)),
            $job->jobKey,
            'task-key-negative-fact',
        );
        self::assertSame(strtolower(Provider::RESOURCE), $fixture->codec->verify($encoded)->resourceKey);
        // Negative SQL fact in the private fixture, not a public way to bypass Publisher.
        Db::name('task_job')->where('job_key', $job->jobKey)->update(['trusted_envelope' => $encoded]);
        self::assertSame('dead', $fixture->worker($provider, $job->jobKey)->runOnce());
        self::assertSame('TASK_PERMISSION_DENIED', $fixture->store->get(1, $job->jobKey)->lastErrorCode);
        self::assertSame(0, $provider->authorizationCalls);
        self::assertSame([], $provider->handled);
    }

    public function testChangingResourceCaseWithoutResigningFailsSignature(): void
    {
        [$fixture, $provider, $job] = $this->queued();
        $original = $fixture->row($job->jobKey)['trusted_envelope'];
        $tampered = str_replace(Provider::RESOURCE, strtolower(Provider::RESOURCE), $original);
        self::assertNotSame($original, $tampered);
        try {
            $fixture->codec->verify($tampered);
            self::fail('Tampered resource case must fail the native signature.');
        } catch (AuthException $error) {
            self::assertSame('CONTEXT_SYSTEM_ACTOR_INVALID', $error->errorCode);
        }
        Db::name('task_job')->where('job_key', $job->jobKey)->update(['trusted_envelope' => $tampered]);
        self::assertSame('dead', $fixture->worker($provider, $job->jobKey)->runOnce());
        self::assertSame('TASK_PERMISSION_DENIED', $fixture->store->get(1, $job->jobKey)->lastErrorCode);
        self::assertSame(0, $provider->authorizationCalls);
        self::assertSame([], $provider->handled);
    }

    public function testCurrentRevocationBeforeFinalFenceHasNoEffect(): void
    {
        [$fixture, $provider, $job] = $this->queued();
        $provider->beforeFinalFence = static function () use ($provider): void {
            $provider->allowed = false;
        };
        self::assertSame('dead', $fixture->worker($provider, $job->jobKey)->runOnce());
        self::assertSame('TASK_PERMISSION_DENIED', $fixture->store->get(1, $job->jobKey)->lastErrorCode);
        self::assertSame(2, $provider->authorizationCalls);
        self::assertSame([], $provider->handled);
    }

    public function testTenantAndLeaseGuardsRemainActive(): void
    {
        [$fixture, $provider, $job] = $this->queued();
        $before = $fixture->state();
        self::assertNull($fixture->worker($provider, $job->jobKey, 2)->runOnce());
        self::assertSame($before, $fixture->state());
        $provider->beforeFinalFence = static function () use ($job): void {
            Db::name('task_job')->where('job_key', $job->jobKey)->update(['lease_token_hash' => str_repeat('0', 64)]);
        };
        self::assertSame('lease_lost', $fixture->worker($provider, $job->jobKey)->runOnce());
        self::assertSame([], $provider->handled);
        self::assertSame('running', $fixture->store->get(1, $job->jobKey)->status);
    }

    public function testPublisherStillRejectsUppercaseHandler(): void
    {
        $fixture = new Fixture();
        $provider = new Provider(handler: 'fixture.Resource-expiry');
        $publisher = $fixture->publisher($this->catalog(), $provider);
        $before = $fixture->state();
        self::rejectTask(fn() => $publisher->publish(
            TaskResourceKeyCompatibilityFixture\context(Provider::RESOURCE),
            $provider->taskType(),
            ['order_id' => 71],
            'task-key-handler-007',
        ), 'TASK_REQUEST_INVALID', 422);
        self::assertSame($before, $fixture->state());
    }

    private function catalog(): ResourceOperationCatalog
    {
        $catalog = $this->createMock(ResourceOperationCatalog::class);
        // TaskWorkerDefinition supplies its module; the alternate catalog lookup must not be consulted.
        $catalog->expects(self::never())->method('find');
        return $catalog;
    }

    private function queued(): array
    {
        $fixture = new Fixture();
        $provider = new Provider();
        $job = $fixture->publisher($this->catalog(), $provider)->publish(
            TaskResourceKeyCompatibilityFixture\context(Provider::RESOURCE),
            $provider->taskType(),
            ['order_id' => 71],
            'task-key-queued-007',
        );
        return [$fixture, $provider, $job];
    }

    private static function rejectTask(callable $operation, string $code, int $status): void
    {
        try {
            $operation();
            self::fail('Expected native Task rejection: ' . $code);
        } catch (TaskJobException $error) {
            self::assertSame($code, $error->problemCode);
            self::assertSame($status, $error->status);
        }
    }
}
