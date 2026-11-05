<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Notification\Delivery\Task;

use PeanutAdmin\Kernel\Async\VerifiedJobEnvelope;
use PeanutAdmin\Kernel\Context\AuthorizedOperationContext;
use PeanutAdmin\Modules\Notification\Delivery\Application\NotificationException;
use PeanutAdmin\Modules\Notification\Delivery\Package;
use PeanutAdmin\Modules\Task\Contract\TaskSubmission;
use PeanutAdmin\Modules\Task\Contract\TaskSubmissionProvider;
use PeanutAdmin\Modules\Task\Contract\TaskWorkerDefinition;

final readonly class OutboxTaskSubmissionProvider implements TaskSubmissionProvider, TaskWorkerDefinition
{
    public function __construct(
        private string $channel,
        private \PeanutAdmin\Modules\Notification\Infrastructure\Authorization\NotificationAsyncAuthorization $authorization,
        private NotificationTaskWorkerDefinition $worker,
    ) {
        if (!in_array($channel, ['inbox', 'sms'], true)) {
            throw NotificationException::invalid();
        }
    }

    public function taskType(): string
    {
        return 'notification.' . $this->channel . '.dispatch';
    }

    public function resourceKey(): string
    {
        return Package::RESOURCE_KEY;
    }

    public function operation(): string
    {
        return 'manage';
    }

    public function ownerModuleKey(): string
    {
        return $this->worker->ownerModuleKey();
    }

    public function handlers(): array
    {
        return $this->worker->handlers();
    }

    public function reauthorize(VerifiedJobEnvelope $envelope): AuthorizedOperationContext
    {
        return $this->worker->reauthorize($envelope);
    }

    public function build(AuthorizedOperationContext $context, array $input): TaskSubmission
    {
        if (array_keys($input) !== ['outbox_key'] || !is_string($input['outbox_key'])
            || preg_match('/^outbox_[0-9a-f]{32}$/D', $input['outbox_key']) !== 1
        ) {
            throw NotificationException::invalid();
        }

        $this->authorization->bind($context, $input['outbox_key']);
        return new TaskSubmission(
            'notification.' . $this->channel,
            ['outbox_key' => $input['outbox_key']],
            $this->channel === 'sms' ? 6 : 3,
        );
    }
}
