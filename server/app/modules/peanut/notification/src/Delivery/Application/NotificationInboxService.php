<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Notification\Delivery\Application;

use PeanutAdmin\Modules\Notification\Delivery\Package;
use PeanutAdmin\Modules\Notification\Delivery\Persistence\NotificationRepository;
use PeanutAdmin\Kernel\Context\AuthorizedOperationContext;
use think\facade\Db;

/** Recipient-owned inbox operations over the module's single notification ledger. */
final readonly class NotificationInboxService
{
    public function __construct(private NotificationRepository $repository) {}

    /** @return array{items:list<NotificationMessage>,page:int,page_size:int,total:int} */
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
        $this->assertOperation($context, 'manage');
        $this->assertMessageKey($messageKey);
        if ($revision < 1) {
            throw NotificationException::invalid();
        }
        return Db::transaction(fn(): NotificationMessage => $this->repository->changeInbox(
            $context->tenantContext,
            $messageKey,
            'read',
            $revision,
        ));
    }

    /** @param list<string> $messageKeys */
    public function bulk(AuthorizedOperationContext $context, array $messageKeys, string $action): int
    {
        $this->assertOperation($context, 'manage');
        if (!in_array($action, ['read', 'archive'], true)
            || count($messageKeys) < 1 || count($messageKeys) > 100
        ) {
            throw NotificationException::invalid();
        }
        $seen = [];
        foreach ($messageKeys as $messageKey) {
            if (!is_string($messageKey) || isset($seen[$messageKey])) {
                throw NotificationException::invalid();
            }
            $this->assertMessageKey($messageKey);
            $seen[$messageKey] = true;
        }
        return Db::transaction(
            fn(): int => $this->repository->bulkChangeInbox($context->tenantContext, $messageKeys, $action),
        );
    }

    private function assertOperation(AuthorizedOperationContext $context, string $operation): void
    {
        if (!hash_equals(Package::RESOURCE_KEY, $context->resourceKey)
            || !hash_equals($operation, $context->operation)
        ) {
            throw NotificationException::denied();
        }
    }

    private function assertMessageKey(string $messageKey): void
    {
        if (preg_match('/^notice_[0-9a-f]{32}$/D', $messageKey) !== 1) {
            throw NotificationException::notFound();
        }
    }
}
