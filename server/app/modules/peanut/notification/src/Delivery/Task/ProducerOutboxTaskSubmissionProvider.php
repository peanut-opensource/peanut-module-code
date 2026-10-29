<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Notification\Delivery\Task;

use PeanutAdmin\Kernel\Context\AuthorizedOperationContext;
use PeanutAdmin\Modules\Notification\Contract\NotificationProducer;
use PeanutAdmin\Modules\Notification\Infrastructure\Authorization\NotificationAsyncAuthorization;
use PeanutAdmin\Modules\Task\Contract\TaskSubmission;
use PeanutAdmin\Modules\Task\Contract\TaskSubmissionProvider;

final readonly class ProducerOutboxTaskSubmissionProvider implements TaskSubmissionProvider
{
    public function __construct(private NotificationProducer $producer, private NotificationAsyncAuthorization $authorization) {}
    public function taskType(): string { return $this->producer->taskType(); }
    public function resourceKey(): string { return $this->producer->resourceKey(); }
    public function operation(): string { return $this->producer->operation(); }
    public function build(AuthorizedOperationContext $context, array $input): TaskSubmission
    {
        if (array_keys($input) !== ['outbox_key'] || !is_string($input['outbox_key']) || preg_match('/^outbox_[0-9a-f]{32}$/D', $input['outbox_key']) !== 1) {
            throw \PeanutAdmin\Modules\Notification\Delivery\Application\NotificationException::invalid();
        }
        $this->authorization->bind($context, $input['outbox_key']);
        return new TaskSubmission($this->producer->handlerKey(), $input, 3);
    }
}
