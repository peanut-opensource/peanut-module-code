<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Notification\Delivery\Task;

use PeanutAdmin\Kernel\Context\AuthorizedOperationContext;
use think\facade\Db;
use PeanutAdmin\Modules\Notification\Delivery\Application\NotificationException;
use PeanutAdmin\Modules\Notification\Delivery\Package;
use PeanutAdmin\Modules\Notification\Delivery\Persistence\NotificationRepository;
use PeanutAdmin\Modules\Task\Contract\JobRecord;
use PeanutAdmin\Modules\Task\Contract\TrustedJobPublisher;

final readonly class NotificationOutboxDispatcher
{
    public function __construct(
        private NotificationRepository $repository,
        private TrustedJobPublisher $publisher,
    ) {}

    public function dispatch(AuthorizedOperationContext $context, string $outboxKey): JobRecord
    {
        if (!hash_equals(Package::RESOURCE_KEY, $context->resourceKey) || !hash_equals('manage', $context->operation)
            || preg_match('/^outbox_[0-9a-f]{32}$/D', $outboxKey) !== 1
        ) {
            throw NotificationException::denied();
        }
        return Db::transaction(function () use ($context, $outboxKey): JobRecord {
            $outbox = $this->repository->outboxForSubmission($context->tenantContext->tenantId, $outboxKey);
            $taskType = 'notification.' . $outbox->channel . '.dispatch';
            $job = $this->publisher->publish($context, $taskType, ['outbox_key' => $outboxKey], $outboxKey);
            $this->repository->bindJob($context->tenantContext->tenantId, $outboxKey, $job->jobKey);
            return $job;
        });
    }
}
