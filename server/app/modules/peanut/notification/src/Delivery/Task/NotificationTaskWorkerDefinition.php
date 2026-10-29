<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Notification\Delivery\Task;

use PeanutAdmin\Kernel\Async\VerifiedJobEnvelope;
use PeanutAdmin\Kernel\Context\AuthorizedOperationContext;
use PeanutAdmin\Modules\Notification\Delivery\Package;
use PeanutAdmin\Modules\Notification\Infrastructure\Authorization\NotificationAsyncAuthorization;
use PeanutAdmin\Modules\Task\Contract\TaskWorkerDefinition;

/** One authorization definition contributes all registered Notification delivery handlers. */
final readonly class NotificationTaskWorkerDefinition implements TaskWorkerDefinition
{
    public function __construct(
        private InboxTaskHandler $inbox,
        private SmsTaskHandler $sms,
        private NotificationAsyncAuthorization $authorization,
    ) {}

    public function ownerModuleKey(): string
    {
        return 'peanut.notification';
    }

    public function resourceKey(): string
    {
        return Package::RESOURCE_KEY;
    }

    public function operation(): string
    {
        return 'manage';
    }

    public function handlers(): array
    {
        return [$this->inbox, $this->sms];
    }

    public function reauthorize(VerifiedJobEnvelope $envelope): AuthorizedOperationContext
    {
        return $this->authorization->reauthorize($envelope);
    }
}
