<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Notification\Delivery\Persistence;

use DateTimeImmutable;
use DateTimeZone;
use JsonException;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Modules\Identity\Contract\TenantMemberDirectory;
use PeanutAdmin\Modules\Notification\Delivery\Application\AttachmentReference;
use PeanutAdmin\Modules\Notification\Delivery\Application\NotificationException;
use PeanutAdmin\Modules\Notification\Delivery\Application\NotificationMessage;
use PeanutAdmin\Modules\Notification\Delivery\Application\OutboxRecord;
use PeanutAdmin\Modules\Notification\Delivery\Application\RecipientSnapshot;
use PeanutAdmin\Modules\Notification\Delivery\Persistence\Model\NotificationAttachmentRecord;
use PeanutAdmin\Modules\Notification\Delivery\Persistence\Model\NotificationEventRecord;
use PeanutAdmin\Modules\Notification\Delivery\Persistence\Model\NotificationMessageRecord;
use PeanutAdmin\Modules\Notification\Delivery\Persistence\Model\NotificationOutboxRecord;
use PeanutAdmin\Modules\Notification\Delivery\Persistence\Model\NotificationTemplateRecord;
use PeanutAdmin\Modules\Notification\Delivery\Persistence\Model\SmsRateBucketRecord;
use PeanutAdmin\Modules\Notification\Delivery\Sms\SmsReceipt;
use think\db\BaseQuery;
use think\db\Raw;

/** Retains notification/outbox invariants while using ThinkPHP persistence. */
final class NotificationStore implements NotificationRepository
{
    public function __construct(private readonly TenantMemberDirectory $members) {}

    public function putTemplate(
        TenantContext $context,
        string $templateKey,
        string $name,
        string $subjectTemplate,
        string $bodyTemplate,
        array $channels,
        array $variables,
        ?int $expectedRevision,
    ): array {
        $this->assertTenantActor($context);
        $existing = $this->templateRow($context->tenantId, $templateKey, true);
        $now = $this->now();
        if ($existing === null) {
            if ($expectedRevision !== null) {
                throw NotificationException::conflict();
            }
            NotificationTemplateRecord::insert([
                'tenant_id' => $context->tenantId,
                'template_key' => $templateKey,
                'name' => $name,
                'subject_template' => $subjectTemplate,
                'body_template' => $bodyTemplate,
                'channels_json' => $this->json($channels),
                'variable_keys_json' => $this->json($variables),
                'status' => 'active',
                'created_by_member_id' => $context->memberId,
                'revision' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } else {
            if ($expectedRevision === null || (int) $existing['revision'] !== $expectedRevision) {
                throw NotificationException::conflict();
            }
            $updated = NotificationTemplateRecord::where('tenant_id', $context->tenantId)
                ->where('template_key', $templateKey)
                ->where('revision', $expectedRevision)
                ->update([
                    'name' => $name,
                    'subject_template' => $subjectTemplate,
                    'body_template' => $bodyTemplate,
                    'channels_json' => $this->json($channels),
                    'variable_keys_json' => $this->json($variables),
                    'revision' => new Raw('revision + 1'),
                    'updated_at' => $now,
                ]);
            if ($updated !== 1) {
                throw NotificationException::conflict();
            }
        }

        return $this->activeTemplate($context->tenantId, $templateKey);
    }

    /** @return array{template_key:string,name:string,subject_template:string,body_template:string,channels:list<string>,variables:list<string>,revision:int} */
    public function activeTemplate(int $tenantId, string $templateKey): array
    {
        $row = $this->templateRow($tenantId, $templateKey, false);
        if ($row === null || $row['status'] !== 'active') {
            throw NotificationException::notFound();
        }

        return [
            'template_key' => (string) $row['template_key'],
            'name' => (string) $row['name'],
            'subject_template' => (string) $row['subject_template'],
            'body_template' => (string) $row['body_template'],
            'channels' => $this->stringList((string) $row['channels_json'], ['inbox', 'sms']),
            'variables' => $this->stringList((string) $row['variable_keys_json']),
            'revision' => (int) $row['revision'],
        ];
    }

    public function createMessage(
        TenantContext $context,
        string $messageKey,
        array $template,
        RecipientSnapshot $recipient,
        string $subject,
        string $body,
        array $attachments,
        ?\PeanutAdmin\Modules\Notification\Contract\NotificationPublicationIdentity $identity = null,
    ): array {
        $this->assertTenantActor($context);
        $this->assertRecipientSnapshot($context, $recipient);
        $payloadHash = hash('sha256', $this->json([$template, $recipient->memberId, $subject, $body, $attachments]));
        if ($identity !== null) {
            $existing = NotificationMessageRecord::where('tenant_id', $context->tenantId)->where('producer_digest', $identity->digest($context->tenantId))
                ->where('recipient_member_id', $recipient->memberId)->lock(true)->find()?->toArray();
            if ($existing !== null) {
                if (!hash_equals((string) $existing['producer_payload_sha256'], $payloadHash)) { throw NotificationException::conflict(); }
                return ['message' => $this->messageById($context->tenantId, (int) $existing['id'], $recipient->memberId),
                    'outbox' => array_map($this->mapOutbox(...), NotificationOutboxRecord::where('tenant_id', $context->tenantId)->where('message_id', $existing['id'])->select()->toArray())];
            }
        }
        $now = $this->now();
        $messageId = (int) NotificationMessageRecord::insertGetId([
            'message_key' => $messageKey,
            'tenant_id' => $context->tenantId,
            'template_key' => $template['template_key'],
            'template_revision' => $template['revision'],
            'recipient_member_id' => $recipient->memberId,
            'recipient_account_id' => $recipient->accountId,
            'recipient_display_name' => $recipient->displayName,
            'subject' => $subject,
            'body' => $body,
            'status' => 'unread',
            'created_by_member_id' => $context->memberId,
            'revision' => 1,
            'created_at' => $now,
            'updated_at' => $now,
            'read_at' => null,
            'archived_at' => null,
            'producer_key' => $identity?->producerKey, 'producer_event_key' => $identity?->eventKey, 'producer_business_key' => $identity?->businessKey,
            'producer_digest' => $identity?->digest($context->tenantId), 'producer_payload_sha256' => $identity === null ? null : $payloadHash,
        ]);
        foreach ($attachments as $attachment) {
            NotificationAttachmentRecord::insert([
                'tenant_id' => $context->tenantId,
                'message_id' => $messageId,
                'file_key' => $attachment->fileKey,
                'original_name' => $attachment->originalName,
                'media_type' => $attachment->mediaType,
                'size_bytes' => $attachment->sizeBytes,
                'sha256' => $attachment->sha256,
            ]);
        }

        $outbox = [];
        foreach ($template['channels'] as $channel) {
            $outboxKey = 'outbox_' . bin2hex(random_bytes(16));
            NotificationOutboxRecord::insert([
                'outbox_key' => $outboxKey,
                'tenant_id' => $context->tenantId,
                'message_id' => $messageId,
                'channel' => $channel,
                'recipient_phone_masked' => $channel === 'sms' ? $recipient->phoneMasked : null,
                'recipient_phone_digest' => $channel === 'sms' ? $recipient->phoneDigest : null,
                'status' => 'pending',
                'revision' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $outbox[] = new OutboxRecord($outboxKey, $context->tenantId, $channel, 'pending', null);
        }
        $this->event($context->tenantId, $messageId, 'tenant.notification.created', $context->memberId, [
            'channel_count' => count($outbox),
            'attachment_count' => count($attachments),
            'template_key' => $template['template_key'],
        ]);

        return [
            'message' => $this->messageById($context->tenantId, $messageId, $recipient->memberId),
            'outbox' => $outbox,
        ];
    }

    public function inbox(int $tenantId, int $memberId, string $status, int $page, int $pageSize): array
    {
        $query = NotificationMessageRecord::where('tenant_id', $tenantId)
            ->where('recipient_member_id', $memberId);
        $this->deliveredInbox($query);
        if ($status !== 'all') {
            $query->where('status', $status);
        }
        $total = (int) (clone $query)->count();
        $rows = $query->field('id')->order('id', 'desc')->page($page, $pageSize)->select()->toArray();

        return [
            'items' => array_values(array_map(
                fn(array $row): NotificationMessage => $this->messageById($tenantId, (int) $row['id'], $memberId),
                $rows,
            )),
            'page' => $page,
            'page_size' => $pageSize,
            'total' => $total,
        ];
    }

    public function changeInbox(
        TenantContext $context,
        string $messageKey,
        string $action,
        int $expectedRevision,
    ): NotificationMessage {
        $row = $this->messageRow($context->tenantId, $context->memberId, $messageKey, true);
        if ($row === null) {
            throw NotificationException::notFound();
        }
        if ((int) $row['revision'] !== $expectedRevision) {
            throw NotificationException::conflict();
        }
        if ($action === 'read' && in_array($row['status'], ['read', 'archived'], true)) {
            return $this->messageById($context->tenantId, (int) $row['id'], $context->memberId);
        }
        if ($action !== 'read') {
            throw NotificationException::invalid();
        }
        $now = $this->now();
        $updated = NotificationMessageRecord::where('id', $row['id'])
            ->where('tenant_id', $context->tenantId)
            ->where('recipient_member_id', $context->memberId)
            ->where('status', 'unread')
            ->where('revision', $expectedRevision)
            ->update([
                'status' => 'read',
                'read_at' => $now,
                'updated_at' => $now,
                'revision' => new Raw('revision + 1'),
            ]);
        if ($updated !== 1) {
            throw NotificationException::conflict();
        }
        $this->event($context->tenantId, (int) $row['id'], 'tenant.notification.read', $context->memberId, []);

        return $this->messageById($context->tenantId, (int) $row['id'], $context->memberId);
    }

    public function bulkChangeInbox(TenantContext $context, array $messageKeys, string $action): int
    {
        $changed = 0;
        $now = $this->now();
        foreach ($messageKeys as $messageKey) {
            $row = $this->messageRow($context->tenantId, $context->memberId, $messageKey, true);
            if ($row === null) {
                throw NotificationException::notFound();
            }
            $newStatus = $action === 'archive' ? 'archived' : 'read';
            if ($row['status'] === $newStatus || ($action === 'read' && $row['status'] === 'archived')) {
                continue;
            }
            $data = [
                'status' => $newStatus,
                'read_at' => $row['read_at'] ?? $now,
                'updated_at' => $now,
                'revision' => new Raw('revision + 1'),
            ];
            if ($newStatus === 'archived') {
                $data['archived_at'] = $now;
            }
            $updated = NotificationMessageRecord::where('id', $row['id'])
                ->where('tenant_id', $context->tenantId)
                ->where('recipient_member_id', $context->memberId)
                ->where('revision', $row['revision'])
                ->update($data);
            if ($updated !== 1) {
                throw NotificationException::conflict();
            }
            ++$changed;
            $this->event(
                $context->tenantId,
                (int) $row['id'],
                $action === 'archive' ? 'tenant.notification.archived' : 'tenant.notification.read',
                $context->memberId,
                ['bulk' => true],
            );
        }

        return $changed;
    }

    public function outboxState(int $tenantId, string $outboxKey, bool $lock = false): OutboxRecord
    {
        return $this->mapOutbox($this->outboxRow($tenantId, $outboxKey, $lock) ?? throw NotificationException::notFound());
    }

    public function publicationIdentity(int $tenantId, string $outboxKey): ?\PeanutAdmin\Modules\Notification\Contract\NotificationPublicationIdentity
    {
        $row = $this->outboxRow($tenantId, $outboxKey, false) ?? throw NotificationException::notFound();
        $message = $this->messageByInternalId($tenantId, (int) $row['message_id']);
        return $message['producer_key'] === null ? null : new \PeanutAdmin\Modules\Notification\Contract\NotificationPublicationIdentity($message['producer_key'], $message['producer_event_key'], $message['producer_business_key']);
    }

    public function bindActor(\PeanutAdmin\Kernel\Context\AuthorizedOperationContext $context, string $outboxKey): void
    {
        $tenant = $context->tenantContext;
        $this->assertTenantActor($tenant);
        $member = $this->members->current($tenant) ?? throw NotificationException::denied();
        foreach ([$tenant->sessionKey, $tenant->clientKey, $tenant->requestId] as $key) {
            if ($key === '' || strlen($key) > 128) { throw NotificationException::denied(); }
        }
        $targets = hash('sha256', $this->json($context->targets));
        $key = hash('sha256', $this->json([$tenant->tenantId, $tenant->accountId, $tenant->memberId, $context->resourceKey, $context->operation, $targets, $tenant->requestId, $outboxKey]));
        $values = ['reference_key' => $key, 'tenant_id' => $tenant->tenantId, 'account_id' => $tenant->accountId, 'member_id' => $tenant->memberId,
            'resource_key' => $context->resourceKey, 'operation' => $context->operation, 'targets_sha256' => $targets, 'request_id' => $tenant->requestId,
            'session_key' => $tenant->sessionKey, 'client_key' => $tenant->clientKey, 'account_security_revision' => $member->accountSecurityRevision,
            'tenant_security_revision' => $member->tenantSecurityRevision, 'member_security_revision' => $member->securityRevision, 'outbox_key' => $outboxKey];
        $existing = Model\NotificationTaskActorRecord::where('tenant_id', $tenant->tenantId)->where('reference_key', $key)->lock(true)->find()?->toArray();
        if ($existing !== null) {
            foreach ($values as $field => $value) { if ((string) $existing[$field] !== (string) $value) { throw NotificationException::conflict(); } }
            return;
        }
        Model\NotificationTaskActorRecord::insert($values + ['created_at' => $this->now()]);
    }

    public function actorReference(\PeanutAdmin\Kernel\Async\VerifiedJobEnvelope $envelope): array
    {
        $outbox = NotificationOutboxRecord::where('tenant_id', $envelope->tenantId)->where('dispatch_job_key', $envelope->operationId)->value('outbox_key');
        if (!is_string($outbox)) { throw NotificationException::denied(); }
        return Model\NotificationTaskActorRecord::where('tenant_id', $envelope->tenantId)->where('account_id', $envelope->accountId)->where('member_id', $envelope->memberId)
            ->where('resource_key', $envelope->resourceKey)->where('operation', $envelope->operation)->where('request_id', $envelope->traceId)
            ->where('targets_sha256', hash('sha256', $this->json($envelope->requestedTargets)))->where('outbox_key', $outbox)->find()?->toArray() ?? throw NotificationException::denied();
    }

    public function replaceJob(int $tenantId, string $outboxKey, string $oldJobKey, string $newJobKey): void
    {
        $row = $this->outboxRow($tenantId, $outboxKey, true) ?? throw NotificationException::notFound();
        if (!in_array($row['status'], ['queued', 'retryable'], true) || $row['dispatch_job_key'] !== $oldJobKey) { throw NotificationException::conflict(); }
        $changed = NotificationOutboxRecord::where('tenant_id', $tenantId)->where('id', $row['id'])->where('dispatch_job_key', $oldJobKey)->update([
            'status' => 'queued', 'dispatch_job_key' => $newJobKey, 'revision' => new Raw('revision + 1'), 'updated_at' => $this->now()]);
        if ($changed !== 1) { throw NotificationException::conflict(); }
        $this->event($tenantId, (int) $row['message_id'], 'tenant.notification.delivery_recovered', null, ['previous_job_key' => $oldJobKey, 'job_key' => $newJobKey]);
    }

    public function outboxForSubmission(int $tenantId, string $outboxKey): OutboxRecord
    {
        $row = $this->outboxRow($tenantId, $outboxKey, false);
        if ($row === null || !in_array($row['status'], ['pending', 'retryable', 'queued'], true)) {
            throw NotificationException::notFound();
        }

        return $this->mapOutbox($row);
    }

    public function bindJob(int $tenantId, string $outboxKey, string $jobKey): void
    {
        $row = $this->outboxRow($tenantId, $outboxKey, true);
        if ($row === null) {
            throw NotificationException::notFound();
        }
        if ($row['status'] === 'queued' && hash_equals((string) $row['dispatch_job_key'], $jobKey)) {
            return;
        }
        if (!in_array($row['status'], ['pending', 'retryable'], true) || $row['dispatch_job_key'] !== null) {
            throw NotificationException::conflict();
        }
        $updated = NotificationOutboxRecord::where('id', $row['id'])
            ->where('tenant_id', $tenantId)
            ->whereIn('status', ['pending', 'retryable'])
            ->whereNull('dispatch_job_key')
            ->update([
                'status' => 'queued',
                'dispatch_job_key' => $jobKey,
                'revision' => new Raw('revision + 1'),
                'updated_at' => $this->now(),
            ]);
        if ($updated !== 1) {
            throw NotificationException::conflict();
        }
    }

    public function deliverInbox(int $tenantId, string $outboxKey, string $jobKey): void
    {
        $row = $this->assertJobOutbox($this->outboxRow($tenantId, $outboxKey, true), 'inbox', $jobKey);
        if ($row['status'] === 'delivered') {
            return;
        }
        $now = $this->now();
        $updated = NotificationOutboxRecord::where('id', $row['id'])
            ->where('tenant_id', $tenantId)
            ->whereIn('status', ['queued', 'processing'])
            ->update([
                'status' => 'delivered',
                'delivered_at' => $now,
                'updated_at' => $now,
                'revision' => new Raw('revision + 1'),
            ]);
        if ($updated !== 1) {
            throw NotificationException::conflict();
        }
        $this->event($tenantId, (int) $row['message_id'], 'tenant.notification.delivered', null, ['channel' => 'inbox']);
    }

    public function beginSms(int $tenantId, string $outboxKey, string $jobKey): SmsDispatch
    {
        $row = $this->assertJobOutbox($this->outboxRow($tenantId, $outboxKey, true), 'sms', $jobKey);
        $message = $this->messageByInternalId($tenantId, (int) $row['message_id']);
        if ($row['status'] === 'delivered') {
            return new SmsDispatch($outboxKey, $tenantId, (int) $message['recipient_member_id'], (string) $row['recipient_phone_digest'], (string) $message['body'], $jobKey, true);
        }
        if (!in_array($row['status'], ['queued', 'retryable', 'processing'], true)) {
            throw NotificationException::conflict();
        }
        $updated = NotificationOutboxRecord::where('id', $row['id'])
            ->where('tenant_id', $tenantId)
            ->whereIn('status', ['queued', 'retryable', 'processing'])
            ->update([
                'status' => 'processing',
                'updated_at' => $this->now(),
                'revision' => new Raw('revision + 1'),
            ]);
        if ($updated !== 1) {
            throw NotificationException::conflict();
        }

        return new SmsDispatch($outboxKey, $tenantId, (int) $message['recipient_member_id'], (string) $row['recipient_phone_digest'], (string) $message['body'], $jobKey);
    }

    public function reserveSmsRate(int $tenantId, string $recipientDigest): bool
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $buckets = [];
        foreach ([['tenant', 60, 60], ['recipient:' . $recipientDigest, 3600, 5]] as [$key, $window, $limit]) {
            $query = SmsRateBucketRecord::where('tenant_id', $tenantId)->where('bucket_key', $key);
            $row = (clone $query)->lock(true)->find()?->toArray();
            if ($row === null) {
                SmsRateBucketRecord::duplicate(['bucket_key'])->insert([
                    'tenant_id' => $tenantId,
                    'bucket_key' => $key,
                    'window_seconds' => $window,
                    'window_started_at' => $this->date($now),
                    'send_count' => 0,
                    'updated_at' => $this->date($now),
                ]);
                $row = (clone $query)->lock(true)->find()?->toArray();
            }
            if (!is_array($row)) {
                throw NotificationException::conflict();
            }
            $started = new DateTimeImmutable((string) $row['window_started_at'], new DateTimeZone('UTC'));
            $count = (int) $row['send_count'];
            if ($started->modify('+' . $window . ' seconds') <= $now) {
                $started = $now;
                $count = 0;
            }
            if ($count >= $limit) {
                return false;
            }
            $buckets[] = [$key, $window, $started, $count + 1];
        }
        foreach ($buckets as [$key, $window, $started, $count]) {
            SmsRateBucketRecord::where('tenant_id', $tenantId)->where('bucket_key', $key)->update([
                'window_seconds' => $window,
                'window_started_at' => $this->date($started),
                'send_count' => $count,
                'updated_at' => $this->date($now),
            ]);
        }

        return true;
    }

    public function completeSms(SmsDispatch $dispatch, SmsReceipt $receipt): void
    {
        $row = $this->assertJobOutbox($this->outboxRow($dispatch->tenantId, $dispatch->outboxKey, true), 'sms', $dispatch->jobKey);
        if ($row['status'] === 'delivered') {
            return;
        }
        if ($row['status'] !== 'processing') {
            throw NotificationException::conflict();
        }
        $now = $this->now();
        $updated = NotificationOutboxRecord::where('id', $row['id'])
            ->where('tenant_id', $dispatch->tenantId)
            ->where('status', 'processing')
            ->update([
                'status' => 'delivered',
                'provider_key' => $receipt->providerKey,
                'provider_message_key' => $receipt->providerMessageKey,
                'provider_receipt_code' => $receipt->receiptCode,
                'last_error_code' => null,
                'delivered_at' => $now,
                'updated_at' => $now,
                'revision' => new Raw('revision + 1'),
            ]);
        if ($updated !== 1) {
            throw NotificationException::conflict();
        }
        $this->event($dispatch->tenantId, (int) $row['message_id'], 'tenant.notification.delivered', null, [
            'channel' => 'sms', 'provider_key' => $receipt->providerKey, 'receipt_code' => $receipt->receiptCode,
        ]);
    }

    public function failSms(SmsDispatch $dispatch, string $safeCode, bool $retryable): void
    {
        if (preg_match('/^[A-Z][A-Z0-9_]{2,63}$/D', $safeCode) !== 1) {
            $safeCode = 'SMS_PROVIDER_UNAVAILABLE';
            $retryable = true;
        }
        $row = $this->assertJobOutbox($this->outboxRow($dispatch->tenantId, $dispatch->outboxKey, true), 'sms', $dispatch->jobKey);
        if ($row['status'] === 'delivered') {
            return;
        }
        $updated = NotificationOutboxRecord::where('id', $row['id'])
            ->where('tenant_id', $dispatch->tenantId)
            ->where('status', 'processing')
            ->update([
                'status' => $retryable ? 'retryable' : 'permanent_failed',
                'last_error_code' => $safeCode,
                'updated_at' => $this->now(),
                'revision' => new Raw('revision + 1'),
            ]);
        if ($updated !== 1) {
            throw NotificationException::conflict();
        }
        $this->event($dispatch->tenantId, (int) $row['message_id'], 'tenant.notification.delivery_failed', null, [
            'channel' => 'sms', 'error_code' => $safeCode, 'retryable' => $retryable,
        ]);
    }

    /** @return array<string, mixed>|null */
    private function templateRow(int $tenantId, string $templateKey, bool $lock): ?array
    {
        return $this->find(NotificationTemplateRecord::where('tenant_id', $tenantId)->where('template_key', $templateKey), $lock);
    }

    /** @return array<string, mixed>|null */
    private function messageRow(int $tenantId, int $memberId, string $messageKey, bool $lock): ?array
    {
        $query = NotificationMessageRecord::where('tenant_id', $tenantId)->where('recipient_member_id', $memberId)->where('message_key', $messageKey);
        $this->deliveredInbox($query);
        return $this->find($query, $lock);
    }

    private function deliveredInbox(BaseQuery $query): void
    {
        $messages = (new NotificationMessageRecord())->getTable();
        $outbox = (new NotificationOutboxRecord())->getTable();
        $query->whereExists(static function (BaseQuery $delivery) use ($messages, $outbox): void {
            $delivery->table($outbox)->whereColumn($outbox . '.tenant_id', '=', $messages . '.tenant_id')->whereColumn($outbox . '.message_id', '=', $messages . '.id')
                ->where($outbox . '.channel', 'inbox')->where($outbox . '.status', 'delivered')->fieldRaw('1');
        });
    }

    /** @return array<string, mixed> */
    private function messageByInternalId(int $tenantId, int $id): array
    {
        $row = NotificationMessageRecord::where('tenant_id', $tenantId)->where('id', $id)->find()?->toArray();
        if ($row === null) {
            throw NotificationException::notFound();
        }
        return $row;
    }

    private function messageById(int $tenantId, int $id, int $memberId): NotificationMessage
    {
        $row = $this->messageByInternalId($tenantId, $id);
        if ((int) $row['recipient_member_id'] !== $memberId) {
            throw NotificationException::notFound();
        }
        $attachments = array_values(array_map(
            static fn(array $item): AttachmentReference => new AttachmentReference(
                (string) $item['file_key'],
                (string) $item['original_name'],
                (string) $item['media_type'],
                (int) $item['size_bytes'],
                (string) $item['sha256'],
            ),
            NotificationAttachmentRecord::where('tenant_id', $tenantId)->where('message_id', $id)
                ->field('file_key,original_name,media_type,size_bytes,sha256')->order('id')->select()->toArray(),
        ));
        return new NotificationMessage(
            (string) $row['message_key'],
            (string) $row['template_key'],
            (int) $row['template_revision'],
            (string) $row['subject'],
            (string) $row['body'],
            (string) $row['status'],
            (int) $row['revision'],
            $this->instant((string) $row['created_at']),
            $row['read_at'] === null ? null : $this->instant((string) $row['read_at']),
            $row['archived_at'] === null ? null : $this->instant((string) $row['archived_at']),
            $attachments,
        );
    }

    /** @return array<string, mixed>|null */
    private function outboxRow(int $tenantId, string $outboxKey, bool $lock): ?array
    {
        return $this->find(NotificationOutboxRecord::where('tenant_id', $tenantId)->where('outbox_key', $outboxKey), $lock);
    }

    /** @param array<string, mixed> $row */
    private function mapOutbox(array $row): OutboxRecord
    {
        return new OutboxRecord((string) $row['outbox_key'], (int) $row['tenant_id'], (string) $row['channel'], (string) $row['status'], $row['dispatch_job_key'] === null ? null : (string) $row['dispatch_job_key']);
    }

    /**
     * @param array<string, mixed>|null $row
     * @return array<string, mixed>
     */
    private function assertJobOutbox(?array $row, string $channel, string $jobKey): array
    {
        if ($row === null || !hash_equals($channel, (string) $row['channel']) || $row['dispatch_job_key'] === null || !hash_equals((string) $row['dispatch_job_key'], $jobKey)) {
            throw NotificationException::notFound();
        }
        return $row;
    }

    private function assertTenantActor(TenantContext $context): void
    {
        if ($this->members->current($context) === null) {
            throw NotificationException::denied();
        }
    }

    private function assertRecipientSnapshot(TenantContext $context, RecipientSnapshot $recipient): void
    {
        $member = $this->members->activeMember($context, $recipient->memberId);
        if ($member === null || $member->accountId !== $recipient->accountId) {
            throw NotificationException::recipientUnavailable();
        }
    }

    /** @param array<string, mixed> $metadata */
    private function event(int $tenantId, int $messageId, string $eventKey, ?int $actorMemberId, array $metadata): void
    {
        NotificationEventRecord::insert([
            'tenant_id' => $tenantId, 'message_id' => $messageId, 'event_key' => $eventKey,
            'actor_member_id' => $actorMemberId, 'metadata_json' => $this->json($metadata), 'occurred_at' => $this->now(),
        ]);
    }

    /** @return array<string, mixed>|null */
    private function find(BaseQuery $query, bool $lock): ?array
    {
        if ($lock) {
            $query->lock(true);
        }
        return $query->find()?->toArray();
    }

    private function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.v');
    }

    private function instant(string $value): string
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.v', $value, new DateTimeZone('UTC'));
        if (!$date instanceof DateTimeImmutable || $date->format('Y-m-d H:i:s.v') !== $value) {
            throw NotificationException::conflict();
        }
        return $date->format('Y-m-d\TH:i:s.v\Z');
    }

    private function date(DateTimeImmutable $date): string
    {
        return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.v');
    }

    private function json(mixed $value): string
    {
        try {
            return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (JsonException) {
            throw NotificationException::invalid();
        }
    }

    /**
     * @param list<string> $allowed
     * @return list<string>
     */
    private function stringList(string $json, array $allowed = []): array
    {
        try {
            $value = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw NotificationException::conflict();
        }
        if (!is_array($value) || !array_is_list($value)) {
            throw NotificationException::conflict();
        }
        $seen = [];
        foreach ($value as $item) {
            if (!is_string($item) || isset($seen[$item]) || ($allowed !== [] && !in_array($item, $allowed, true))) {
                throw NotificationException::conflict();
            }
            $seen[$item] = true;
        }
        return array_keys($seen);
    }
}
