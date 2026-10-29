<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Notification\Delivery\Application;

use PeanutAdmin\Kernel\Context\AuthorizedOperationContext;
use think\facade\Db;
use PeanutAdmin\Modules\Notification\Delivery\Package;
use PeanutAdmin\Modules\Notification\Delivery\Persistence\NotificationRepository;

final readonly class NotificationService
{
    public function __construct(
        private NotificationRepository $repository,
        private RecipientResolver $recipients,
        private ?AttachmentResolver $attachments,
        private TemplateRenderer $renderer,
        private \PeanutAdmin\Modules\Notification\Contract\NotificationProducerRegistry $producers,
        private \PeanutAdmin\Kernel\Module\ModuleAvailability $modules,
        private \PeanutAdmin\Modules\Notification\Delivery\Task\InboxTaskHandler $inboxDelivery,
        private ?\PeanutAdmin\Modules\Task\Contract\TrustedJobPublisher $publisher = null,
    ) {}

    /**
     * @param list<string> $channels
     * @param list<string> $variables
     * @return array<string, mixed>
     */
    public function putTemplate(
        AuthorizedOperationContext $context,
        string $templateKey,
        string $name,
        string $subjectTemplate,
        string $bodyTemplate,
        array $channels,
        array $variables,
        ?int $expectedRevision,
    ): array {
        $this->assertOperation($context, 'manage');
        $this->assertTemplateInput($templateKey, $name, $subjectTemplate, $bodyTemplate, $channels, $variables);

        return Db::transaction(fn(): array => $this->repository->putTemplate(
            $context->tenantContext,
            $templateKey,
            trim($name),
            $subjectTemplate,
            $bodyTemplate,
            array_values(array_unique($channels)),
            array_values(array_unique($variables)),
            $expectedRevision,
        ));
    }

    /**
     * @param list<array{member_id: int, variables: array<string, scalar|null>}> $recipientInputs
     * @param list<string> $fileKeys
     * @return array{messages: list<NotificationMessage>, outbox: list<OutboxRecord>}
     */
    public function publish(
        AuthorizedOperationContext $context,
        string $templateKey,
        array $recipientInputs,
        array $fileKeys = [],
    ): array {
        $this->assertOperation($context, 'manage');
        if (strlen($templateKey) > 64 || preg_match('/^[a-z][a-z0-9]*(?:[.-][a-z0-9]+)*$/D', $templateKey) !== 1
            || count($recipientInputs) < 1 || count($recipientInputs) > 100
            || count($fileKeys) > 10
        ) {
            throw NotificationException::invalid();
        }
        $seenFiles = [];
        foreach ($fileKeys as $fileKey) {
            if (isset($seenFiles[$fileKey])) {
                throw NotificationException::attachmentUnavailable();
            }
            $seenFiles[$fileKey] = true;
        }

        return Db::transaction(fn(): array => $this->materialize($context, $this->repository->activeTemplate($context->tenantContext->tenantId, $templateKey), $recipientInputs, $fileKeys, null));
    }

    private function materialize(AuthorizedOperationContext $context, array $template, array $recipientInputs, array $fileKeys, ?\PeanutAdmin\Modules\Notification\Contract\NotificationPublicationIdentity $identity): array
    {
        $channels = $template['channels'];
        $requiresSms = in_array('sms', $channels, true);
        $attachmentSnapshots = [];
        foreach ($fileKeys as $fileKey) {
            $attachmentSnapshots[] = ($this->attachments ?? throw NotificationException::attachmentUnavailable())->snapshot($context->tenantContext, $fileKey);
        }

        $messages = [];
        $outbox = [];
        $seenMembers = [];
        foreach ($recipientInputs as $input) {
            if (isset($seenMembers[$input['member_id']])) {
                throw NotificationException::invalid();
            }
            $seenMembers[$input['member_id']] = true;
            $recipient = $this->recipients->snapshot($context->tenantContext, $input['member_id'], $requiresSms);
            $subject = $this->renderer->render(
                $template['subject_template'],
                $template['variables'],
                $input['variables'],
                255,
            );
            $body = $this->renderer->render(
                $template['body_template'],
                $template['variables'],
                $input['variables'],
                $requiresSms ? 1000 : 10000,
            );
            $created = $this->repository->createMessage(
                $context->tenantContext,
                'notice_' . ($identity === null ? bin2hex(random_bytes(16)) : substr(hash('sha256', $identity->digest($context->tenantContext->tenantId) . ':' . $recipient->memberId), 0, 32)),
                $template,
                $recipient,
                $subject,
                $body,
                $attachmentSnapshots,
                $identity,
            );
            $messages[] = $created['message'];
            array_push($outbox, ...$created['outbox']);
        }

        return ['messages' => $messages, 'outbox' => $outbox];
    }

    /** Only a registered producer may materialize its own committed immutable business fact. */
    public function publishProduced(AuthorizedOperationContext $basis, \PeanutAdmin\Modules\Notification\Contract\NotificationPublicationIdentity $identity): array
    {
        $pdo = Db::connect()->getPdo();
        if (!$pdo instanceof \PDO || $pdo->inTransaction()) { throw NotificationException::invalid('NOTIFICATION_COMMITTED_FACT_REQUIRED'); }
        $this->assertAvailable($basis->tenantContext->tenantId);
        $producer = $this->producers->require($identity->producerKey);
        $context = $producer->authorize($basis->tenantContext, $identity);
        $publication = $producer->publication($context, $identity);
        $template = $publication['template'];
        $this->assertTemplateInput($template['template_key'], $template['name'], $template['subject_template'], $template['body_template'], $template['channels'], $template['variables']);
        if ($template['channels'] !== ['inbox'] || count($publication['recipients']) < 1 || count($publication['recipients']) > 100 || count($publication['file_keys']) > 10) { throw NotificationException::invalid(); }
        return Db::transaction(fn(): array => $this->materialize($context, $template, $publication['recipients'], $publication['file_keys'], $identity));
    }

    /** Registered producer workers use the original Inbox handler and its native final lease/authorization fences. */
    public function deliverProduced(AuthorizedOperationContext $context, \PeanutAdmin\Modules\Task\Contract\JobExecution $execution): void
    {
        $this->assertAvailable($context->tenantContext->tenantId);
        $this->inboxDelivery->handle($context, $execution);
    }

    public function dispatchProduced(AuthorizedOperationContext $basis, string $outboxKey): \PeanutAdmin\Modules\Task\Contract\JobRecord
    {
        $this->assertAvailable($basis->tenantContext->tenantId);
        $identity = $this->repository->publicationIdentity($basis->tenantContext->tenantId, $outboxKey) ?? throw NotificationException::denied();
        $producer = $this->producers->require($identity->producerKey);
        $context = $producer->authorize($basis->tenantContext, $identity);
        if ($this->publisher === null) { throw NotificationException::denied(); }
        return Db::transaction(function () use ($producer, $context, $outboxKey) {
            $outbox = $this->repository->outboxForSubmission($context->tenantContext->tenantId, $outboxKey);
            if ($outbox->channel !== 'inbox') { throw NotificationException::denied(); }
            $job = $this->publisher->publish($context, $producer->taskType(), ['outbox_key' => $outboxKey], $outboxKey);
            $this->repository->bindJob($context->tenantContext->tenantId, $outboxKey, $job->jobKey);
            return $job;
        });
    }

    public function deliveryState(AuthorizedOperationContext $basis, string $outboxKey): OutboxRecord
    {
        $this->assertAvailable($basis->tenantContext->tenantId);
        $identity = $this->repository->publicationIdentity($basis->tenantContext->tenantId, $outboxKey) ?? throw NotificationException::denied();
        $this->producers->require($identity->producerKey)->authorizeRead($basis->tenantContext, $identity);
        return $this->repository->outboxState($basis->tenantContext->tenantId, $outboxKey);
    }

    public function assertPublicationSource(AuthorizedOperationContext $basis, \PeanutAdmin\Modules\Notification\Contract\NotificationPublicationIdentity $identity): void
    {
        $this->assertAvailable($basis->tenantContext->tenantId);
        $this->producers->require($identity->producerKey)->authorize($basis->tenantContext, $identity);
    }

    public function recoverProduced(AuthorizedOperationContext $basis, AuthorizedOperationContext $taskContext, string $outboxKey, int $expectedJobRevision, string $strategy, \PeanutAdmin\Modules\Task\Contract\TaskJobService $jobs): OutboxRecord
    {
        $this->assertAvailable($basis->tenantContext->tenantId);
        if (!in_array($strategy, ['retry_original', 'reauthorize'], true) || $taskContext->tenantContext !== $basis->tenantContext || $this->publisher === null) { throw NotificationException::denied(); }
        $identity = $this->repository->publicationIdentity($basis->tenantContext->tenantId, $outboxKey) ?? throw NotificationException::denied();
        $producer = $this->producers->require($identity->producerKey);
        $context = $producer->authorize($basis->tenantContext, $identity);
        return Db::transaction(function () use ($basis, $taskContext, $outboxKey, $expectedJobRevision, $strategy, $jobs, $producer, $context): OutboxRecord {
            $observed = $this->repository->outboxState($context->tenantContext->tenantId, $outboxKey);
            if ($observed->jobKey === null) { throw NotificationException::conflict(); }
            $old = $jobs->detailForRecovery($taskContext, $observed->jobKey, $expectedJobRevision);
            $outbox = $this->repository->outboxState($context->tenantContext->tenantId, $outboxKey, true);
            if ($old->status !== 'dead' || $old->taskType !== $producer->taskType() || $outbox->jobKey !== $old->jobKey || !in_array($outbox->status, ['queued', 'retryable'], true)) { throw NotificationException::conflict(); }
            $job = $strategy === 'retry_original' ? $jobs->retry($taskContext, $old->jobKey, $old->revision)
                : $this->publisher->publish($context, $producer->taskType(), ['outbox_key' => $outboxKey], 'notification-recovery:' . hash('sha256', $old->jobKey . ':' . $old->revision));
            $this->repository->replaceJob($basis->tenantContext->tenantId, $outboxKey, $old->jobKey, $job->jobKey);
            return $this->repository->outboxState($basis->tenantContext->tenantId, $outboxKey);
        });
    }

    private function assertAvailable(int $tenantId): void
    {
        $this->modules->assertAvailable(\PeanutAdmin\Kernel\Tenancy\TenantScope::fromTrustedContext($tenantId, 'notification'), Package::MODULE_KEY, new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
    }

    /** @return array{items: list<NotificationMessage>, page: int, page_size: int, total: int} */
    public function inbox(AuthorizedOperationContext $context, string $status, int $page, int $pageSize): array
    {
        $this->assertOperation($context, 'read');
        if (!in_array($status, ['unread', 'read', 'archived', 'all'], true)
            || $page < 1 || $pageSize < 1 || $pageSize > 100
        ) {
            throw NotificationException::invalid();
        }

        return $this->repository->inbox(
            $context->tenantContext->tenantId,
            $context->tenantContext->memberId,
            $status,
            $page,
            $pageSize,
        );
    }

    public function markRead(AuthorizedOperationContext $context, string $messageKey, int $revision): NotificationMessage
    {
        $this->assertOperation($context, 'read');
        $this->assertMessageKey($messageKey);

        return Db::transaction(
            fn(): NotificationMessage => $this->repository->changeInbox(
                $context->tenantContext,
                $messageKey,
                'read',
                $revision,
            ),
        );
    }

    /** @param list<string> $messageKeys */
    public function bulk(AuthorizedOperationContext $context, array $messageKeys, string $action): int
    {
        $this->assertOperation($context, 'read');
        if (!in_array($action, ['read', 'archive'], true)
            || count($messageKeys) < 1 || count($messageKeys) > 100
        ) {
            throw NotificationException::invalid();
        }
        $seenMessages = [];
        foreach ($messageKeys as $messageKey) {
            if (isset($seenMessages[$messageKey])) {
                throw NotificationException::invalid();
            }
            $seenMessages[$messageKey] = true;
            $this->assertMessageKey($messageKey);
        }

        return Db::transaction(
            fn(): int => $this->repository->bulkChangeInbox($context->tenantContext, $messageKeys, $action),
        );
    }

    private function assertOperation(AuthorizedOperationContext $context, string $operation): void
    {
        $this->assertAvailable($context->tenantContext->tenantId);
        if (!hash_equals(Package::RESOURCE_KEY, $context->resourceKey) || !hash_equals($operation, $context->operation)) {
            throw NotificationException::denied();
        }
    }

    /**
     * @param list<string> $channels
     * @param list<string> $variables
     */
    private function assertTemplateInput(
        string $key,
        string $name,
        string $subject,
        string $body,
        array $channels,
        array $variables,
    ): void {
        if (preg_match('/^[a-z][a-z0-9]*(?:[.-][a-z0-9]+)*$/D', $key) !== 1 || strlen($key) > 64
            || trim($name) === '' || mb_strlen(trim($name)) > 160
            || $subject === '' || mb_strlen($subject) > 255 || $body === '' || mb_strlen($body) > 10000
            || str_contains($name . $subject . $body, "\0")
            || $channels === [] || count($channels) > 2 || count($variables) > 32
        ) {
            throw NotificationException::invalid('NOTIFICATION_TEMPLATE_INVALID');
        }
        $seenChannels = [];
        foreach ($channels as $channel) {
            if (!in_array($channel, ['inbox', 'sms'], true) || isset($seenChannels[$channel])) {
                throw NotificationException::invalid('NOTIFICATION_TEMPLATE_INVALID');
            }
            $seenChannels[$channel] = true;
        }
        $seenVariables = [];
        foreach ($variables as $variable) {
            if (preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $variable) !== 1
                || isset($seenVariables[$variable])
            ) {
                throw NotificationException::invalid('NOTIFICATION_TEMPLATE_INVALID');
            }
            $seenVariables[$variable] = true;
        }
        foreach ([$subject, $body] as $template) {
            preg_match_all('/\{\{([^{}]+)\}\}/', $template, $matches);
            foreach ($matches[1] as $used) {
                if (!in_array($used, $variables, true)) {
                    throw NotificationException::invalid('NOTIFICATION_TEMPLATE_INVALID');
                }
            }
        }
    }

    private function assertMessageKey(string $messageKey): void
    {
        if (preg_match('/^notice_[0-9a-f]{32}$/D', $messageKey) !== 1) {
            throw NotificationException::notFound();
        }
    }
}
