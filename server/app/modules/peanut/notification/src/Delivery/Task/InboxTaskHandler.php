<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Notification\Delivery\Task;

use PeanutAdmin\Kernel\Context\AuthorizedOperationContext;
use think\facade\Db;
use PeanutAdmin\Modules\Notification\Delivery\Application\NotificationException;
use PeanutAdmin\Modules\Notification\Delivery\Package;
use PeanutAdmin\Modules\Notification\Delivery\Persistence\NotificationRepository;
use PeanutAdmin\Modules\Task\Contract\JobExecution;
use PeanutAdmin\Modules\Task\Contract\RetryableTaskException;
use PeanutAdmin\Modules\Task\Contract\TaskHandler;
use Throwable;

final readonly class InboxTaskHandler implements TaskHandler
{
    public function __construct(
        private NotificationRepository $repository,
        private \PeanutAdmin\Modules\Notification\Infrastructure\Authorization\NotificationAsyncAuthorization $authorization,
    ) {}

    public function key(): string
    {
        return 'notification.inbox';
    }

    public function handle(AuthorizedOperationContext $context, JobExecution $execution): void
    {
        $outboxKey = $this->outboxKey($execution);
        $checked = $this->authorization->authorizeCurrent($context->tenantContext, $outboxKey);
        if ($context->tenantContext->tenantId !== $execution->tenantId || $checked->resourceKey !== $context->resourceKey || $checked->operation !== $context->operation
            || json_encode($checked->targets, JSON_THROW_ON_ERROR) !== json_encode($context->targets, JSON_THROW_ON_ERROR)) {
            throw NotificationException::denied();
        }
        $execution->checkpoint();
        try {
            Db::transaction(function () use ($execution, $outboxKey): void {
                $execution->assertLeaseOwned();
                $this->repository->deliverInbox($execution->tenantId, $outboxKey, $execution->jobKey);
                $execution->assertLeaseOwned();
            });
        } catch (NotificationException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new RetryableTaskException('NOTIFICATION_INBOX_PERSISTENCE_FAILED');
        }
    }

    private function outboxKey(JobExecution $execution): string
    {
        if (array_keys($execution->payload) !== ['outbox_key'] || !is_string($execution->payload['outbox_key'])
            || preg_match('/^outbox_[0-9a-f]{32}$/D', $execution->payload['outbox_key']) !== 1
        ) {
            throw NotificationException::invalid();
        }
        return $execution->payload['outbox_key'];
    }
}
