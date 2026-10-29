<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Notification\Delivery\Task;

use PeanutAdmin\Kernel\Context\AuthorizedOperationContext;
use think\facade\Db;
use PeanutAdmin\Modules\Notification\Delivery\Application\NotificationException;
use PeanutAdmin\Modules\Notification\Delivery\Package;
use PeanutAdmin\Modules\Notification\Delivery\Persistence\NotificationRepository;
use PeanutAdmin\Modules\Notification\Delivery\Persistence\SmsDispatch;
use PeanutAdmin\Modules\Notification\Delivery\Sms\SmsProvider;
use PeanutAdmin\Modules\Notification\Delivery\Sms\SmsProviderException;
use PeanutAdmin\Modules\Notification\Delivery\Sms\SmsRecipientResolver;
use PeanutAdmin\Modules\Notification\Delivery\Sms\SmsSendRequest;
use PeanutAdmin\Modules\Task\Contract\JobExecution;
use PeanutAdmin\Modules\Task\Contract\LeaseLostException;
use PeanutAdmin\Modules\Task\Contract\RetryableTaskException;
use PeanutAdmin\Modules\Task\Contract\TaskHandler;
use Throwable;

final readonly class SmsTaskHandler implements TaskHandler
{
    public function __construct(
        private NotificationRepository $repository,
        private SmsRecipientResolver $recipients,
        private SmsProvider $provider,
    ) {}

    public function key(): string
    {
        return 'notification.sms';
    }

    public function handle(AuthorizedOperationContext $context, JobExecution $execution): void
    {
        if ($context->tenantContext->tenantId !== $execution->tenantId
            || !hash_equals(Package::RESOURCE_KEY, $context->resourceKey)
            || !hash_equals('manage', $context->operation)
        ) {
            throw NotificationException::denied();
        }
        $outboxKey = $this->outboxKey($execution);
        $execution->checkpoint();
        try {
            $dispatch = Db::transaction(
                function () use ($execution, $outboxKey): SmsDispatch {
                    $execution->assertLeaseOwned();
                    return $this->repository->beginSms($execution->tenantId, $outboxKey, $execution->jobKey);
                },
            );
        } catch (NotificationException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new RetryableTaskException('SMS_OUTBOX_PERSISTENCE_FAILED');
        }
        if ($dispatch->alreadyDelivered) {
            return;
        }
        try {
            $recipient = $this->recipients->resolve($context->tenantContext, $dispatch->recipientMemberId);
        } catch (NotificationException) {
            $execution->assertLeaseOwned();
            $this->recordFailure($execution, $dispatch, 'SMS_RECIPIENT_UNAVAILABLE', false);
            throw NotificationException::invalid('SMS_RECIPIENT_UNAVAILABLE');
        } catch (Throwable) {
            $execution->assertLeaseOwned();
            $this->recordFailure($execution, $dispatch, 'SMS_RECIPIENT_LOOKUP_FAILED', true);
            throw new RetryableTaskException('SMS_RECIPIENT_LOOKUP_FAILED');
        }
        if (!hash_equals($dispatch->recipientDigest(), $recipient->digest)) {
            $execution->assertLeaseOwned();
            $this->recordFailure($execution, $dispatch, 'SMS_RECIPIENT_CHANGED', false);
            throw NotificationException::invalid('SMS_RECIPIENT_CHANGED');
        }
        $execution->checkpoint();
        try {
            $rateAllowed = Db::transaction(
                function () use ($execution, $dispatch): bool {
                    $execution->assertLeaseOwned();
                    return $this->repository->reserveSmsRate($dispatch->tenantId, $dispatch->recipientDigest());
                },
            );
        } catch (Throwable) {
            $execution->assertLeaseOwned();
            $this->recordFailure($execution, $dispatch, 'SMS_RATE_CHECK_FAILED', true);
            throw new RetryableTaskException('SMS_RATE_CHECK_FAILED');
        }
        if (!$rateAllowed) {
            $execution->assertLeaseOwned();
            $this->recordFailure($execution, $dispatch, 'SMS_RATE_LIMITED', true);
            throw new RetryableTaskException('SMS_RATE_LIMITED');
        }
        $execution->checkpoint();
        try {
            $receipt = $this->provider->send(new SmsSendRequest(
                $execution->jobKey,
                $dispatch->tenantId,
                $dispatch->outboxKey,
                $recipient->number(),
                $dispatch->messageBody(),
            ));
            if (!hash_equals($this->provider->key(), $receipt->providerKey)) {
                throw SmsProviderException::permanent('SMS_PROVIDER_RECEIPT_INVALID');
            }
        } catch (SmsProviderException $exception) {
            $execution->assertLeaseOwned();
            $this->recordFailure($execution, $dispatch, $exception->safeCode, $exception->retryable);
            if ($exception->retryable) {
                throw new RetryableTaskException($exception->safeCode);
            }
            throw NotificationException::invalid($exception->safeCode);
        } catch (NotificationException) {
            $execution->assertLeaseOwned();
            $this->recordFailure($execution, $dispatch, 'SMS_PROVIDER_RECEIPT_INVALID', false);
            throw NotificationException::invalid('SMS_PROVIDER_RECEIPT_INVALID');
        } catch (Throwable) {
            $execution->assertLeaseOwned();
            $this->recordFailure($execution, $dispatch, 'SMS_PROVIDER_UNAVAILABLE', true);
            throw new RetryableTaskException('SMS_PROVIDER_UNAVAILABLE');
        }
        $execution->assertLeaseOwned();
        try {
            Db::transaction(function () use ($execution, $dispatch, $receipt): void {
                $execution->assertLeaseOwned();
                $this->repository->completeSms($dispatch, $receipt);
            });
        } catch (Throwable) {
            // The provider accepted this idempotency key, but its receipt was not
            // committed locally. A retry must reconcile through the same key.
            $this->recordFailure($execution, $dispatch, 'SMS_DELIVERY_COMMIT_UNKNOWN', true);
            throw new RetryableTaskException('SMS_DELIVERY_COMMIT_UNKNOWN');
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

    private function recordFailure(JobExecution $execution, SmsDispatch $dispatch, string $safeCode, bool $retryable): void
    {
        try {
            Db::transaction(function () use ($execution, $dispatch, $safeCode, $retryable): void {
                $execution->assertLeaseOwned();
                $this->repository->failSms($dispatch, $safeCode, $retryable);
            });
        } catch (LeaseLostException $exception) {
            throw $exception;
        } catch (Throwable) {
            // The task classification is authoritative even when evidence
            // persistence is the dependency that failed.
        }
    }
}
