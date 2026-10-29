<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\File\Service\Storage;

use PeanutAdmin\Modules\File\Contract\FileStorage;
use app\common\exception\BusinessException;
use PeanutAdmin\Modules\File\Composition\Storage\StorageDriverFactory;
use PeanutAdmin\Modules\File\Infrastructure\Storage\StorageAccess;
use app\common\tenancy\DataScopePolicy;
use PeanutAdmin\Modules\File\Value\Storage\StoragePath;
use PeanutAdmin\Modules\File\Value\Storage\StoragePurpose;
use PeanutAdmin\Modules\File\Model\Storage\StorageAccount;
use PeanutAdmin\Modules\File\Model\Storage\FileObject;
use PeanutAdmin\Modules\File\Model\Storage\FileImmutableSnapshot;
use PeanutAdmin\Modules\File\Model\Storage\StorageRoute;
use PeanutAdmin\Modules\File\Model\Storage\StorageSpace;
use PeanutAdmin\Modules\File\Infrastructure\Storage\DatabaseReplayGuard;
use DateTimeImmutable;
use DateTimeZone;
use PeanutAdmin\FileMedia\Delivery\DeliveryVisibility;
use PeanutAdmin\FileMedia\Delivery\ReplayMode;
use PeanutAdmin\FileMedia\Delivery\SignedDeliveryTokenService;
use PeanutAdmin\FileMedia\Storage\StorageObjectKey;
use PeanutAdmin\Modules\Identity\Tenancy\DefaultTenantContextResolver;
use PeanutAdmin\Modules\Identity\Contract\AdminDirectoryQuery;
use think\db\BaseQuery;
use think\db\Raw;
use think\facade\Db;
use PeanutAdmin\Kernel\Auth\TenantContext;
use app\common\execution\CurrentExecutionContext;
use app\common\infrastructure\runtime\OperationalLog;
use PeanutAdmin\Modules\File\Service\FileAuthorizationService;
use PeanutAdmin\Modules\File\Model\Storage\FileReference;
use app\common\services\audit\AuditContractHost;

final readonly class StorageService implements FileStorage, \PeanutAdmin\Modules\File\Contract\FileDocumentSnapshots
{
    public const DELIVERY_URL_TTL = 600;
    private SignedDeliveryTokenService $deliveryTokens;
    /** In-process receipts for objects created by this exact operation; never serialized or exported. */
    private \stdClass $newUploads;

    public function __construct(
        private StorageDriverFactory $drivers,
        private DataScopePolicy $dataScopePolicy,
        private DefaultTenantContextResolver $defaultTenant,
        private string $signingSecret,
        private string $applicationOrigin,
        private AdminDirectoryQuery $directory,
        private FileAuthorizationService $authorization,
        private CurrentExecutionContext $execution,
        private AuditContractHost $audit,
        private \PeanutAdmin\Modules\ContentTools\Service\ContentCapabilitiesService $capabilities,
        private \PeanutAdmin\Modules\File\Service\FileDocumentReferenceRegistry $documents,
    ) {
        if (strlen($this->signingSecret) < 32) {
            throw new \RuntimeException('文件签名配置无效');
        }
        $this->deliveryTokens = new SignedDeliveryTokenService(
            $this->signingSecret,
            new DatabaseReplayGuard(),
            self::DELIVERY_URL_TTL,
        );
        $this->newUploads = new \stdClass();
    }

    public function storePath(
        int $tenantId,
        ?int $memberId,
        string $purpose,
        string $sourcePath,
        string $originalName,
        string $mediaType,
    ): array {
        if ($tenantId < 1 || !is_file($sourcePath) || !is_readable($sourcePath)) {
            throw new \InvalidArgumentException('待存储文件无效');
        }
        if (($pdo = Db::connect()->getPdo()) instanceof \PDO && $pdo->inTransaction()) {
            throw BusinessException::conflict('FILE_UPLOAD_OUTER_TRANSACTION_DENIED', '文件写入意图必须在外部写入之前持久完成');
        }
        $access = StoragePurpose::accessType($purpose);
        $current = $this->execution->current();
        $uploadActor = $current instanceof \app\common\execution\AdminExecutionContext ? $current->tenant
            : ($current instanceof \app\common\execution\ConsumerExecutionContext ? $current->member : null);
        if (($access === 'public' || $purpose === 'export.xlsx')
            && ($uploadActor === null || $uploadActor->tenantId !== $tenantId || $uploadActor->memberId !== $memberId)) {
            throw BusinessException::forbidden('FILE_UPLOAD_ACTOR_REQUIRED', '文件交付需要当前可信上传身份');
        }
        $originalName = self::filename($originalName);
        $fileKey = 'file_' . bin2hex(random_bytes(16));
        $objectKey = StoragePath::objectKey(
            $tenantId,
            $purpose,
            $fileKey,
            (string) pathinfo($originalName, PATHINFO_EXTENSION),
        );
        $route = $this->route($purpose, $access);
        $driver = $this->drivers->make($route, $route);
        $size = filesize($sourcePath);
        $sha256 = hash_file('sha256', $sourcePath);
        if (!is_int($size) || !is_string($sha256)) {
            throw new \RuntimeException('文件信息读取失败');
        }
        $detectedType = (new \finfo(FILEINFO_MIME_TYPE))->file($sourcePath);
        if (!is_string($detectedType) || $detectedType === '') {
            throw new \RuntimeException('文件真实类型无法识别');
        }
        $mediaType = $detectedType;
        $attributes = [
            'file_key' => $fileKey,
            'purpose' => $purpose,
            'access_type' => $access,
            'storage_space_id' => (int) $route['space_id'],
            'object_key' => $objectKey,
            'disposition' => StoragePurpose::disposition($purpose),
            'original_name' => $originalName,
            'media_type' => $mediaType !== '' ? $mediaType : 'application/octet-stream',
            'size_bytes' => $size,
            'sha256' => $sha256,
            'created_by_member_id' => $memberId && $memberId > 0 ? $memberId : null,
        ];
        $this->reserveObject($tenantId, $attributes);
        $recordWrite = function (string $event, array $metadata) use ($uploadActor, $tenantId, $memberId, $fileKey): void {
            if ($uploadActor instanceof TenantContext) {
                $this->audit->appendTenantMember($uploadActor, $event, 'file.upload.write', 'file', $fileKey, null, null, 0, null, $metadata);
            } else {
                $this->audit->appendTenantSystem($tenantId, $event, 'file.upload.write', $this->execution->requestId(),
                    $metadata + ['file_key' => $fileKey, 'initiating_member_id' => $memberId]);
            }
        };
        // Durable intent precedes the external write; the reservation keeps its exact key and byte identity.
        $recordWrite('file.upload.write_intent', [
            'object_key' => $objectKey, 'storage_space_id' => $attributes['storage_space_id'],
            'size_bytes' => $size, 'sha256' => $sha256, 'provider_write_outcome' => 'not_started',
        ]);
        $putAcknowledged = false;
        try {
            $driver->put($objectKey, $sourcePath);
            $putAcknowledged = true;
            $recordWrite('file.upload.write_acknowledged', ['provider_write_outcome' => 'acknowledged']);
            if (!$this->markObjectReady($tenantId, $fileKey)) {
                throw new \RuntimeException('文件对象账本未能切换到 ready');
            }
        } catch (\Throwable $error) {
            // A thrown put may already have stored bytes. Neither this unknown result nor a
            // subsequent ledger failure authorizes deleting or uploading the reserved key again.
            $failureRecorded = false;
            try {
                $failureRecorded = $this->markObjectWriteFailed($tenantId, $fileKey);
            } catch (\Throwable) {
                // Preserve the original provider/ledger error and pending reservation for observation.
            }
            $metadata = [
                'provider_write_outcome' => $putAcknowledged ? 'acknowledged' : 'unknown',
                'write_failed_recorded' => $failureRecorded, 'original_key_observation_required' => true,
            ];
            try {
                $recordWrite('file.upload.write_unconfirmed', $metadata);
            } catch (\Throwable) {
                // The durable pre-write intent remains even if the current database is unavailable.
            }
            OperationalLog::warning($this->execution, 'file_upload_write_unconfirmed',
                $metadata + ['file_key' => $fileKey, 'exception' => $error::class]);
            throw $error;
        }

        $this->newUploads->{$fileKey} = ['tenant_id' => $tenantId, 'member_id' => $memberId,
            'request_id' => $this->execution->requestId(), 'object_key' => $objectKey,'driver' => $driver,'attributes' => $attributes];
        $stored = [
            'file_key' => $fileKey,
            'object_key' => $objectKey,
            'access_type' => $access,
            'url' => '',
            'original_name' => $originalName,
        ];
        if ($access === 'private' && $purpose !== 'export.xlsx') {
            return $stored;
        }
        try {
            $object = $this->deliverableObjectForTenant($tenantId, $fileKey);
            if ($object === null) {
                throw new \RuntimeException('文件对象当前不可交付');
            }
            $stored['url'] = $purpose === 'export.xlsx' ? $this->accessUrlForTenant($tenantId, $fileKey)
                : ($access === 'public' ? $this->url($object) : '');
            return $stored;
        } catch (\Throwable $error) {
            try {
                $this->discardFailedUpload($uploadActor, $stored);
            } catch (\Throwable $compensation) {
                \app\common\infrastructure\runtime\OperationalLog::warning($this->execution, 'file_upload_compensation_failed', [
                    'file_key' => $fileKey, 'exception' => $compensation::class,
                ]);
            }
            throw $error;
        }
    }

    public function publicUrl(string $reference): string
    {
        $reference = trim($reference);
        if ($reference === '') {
            return '';
        }
        if (preg_match('/^file_[0-9a-f]{32}$/D', $reference) !== 1) {
            return '';
        }
        $object = $this->publicObject($reference);
        return $object === null ? '' : $this->url($object);
    }

    public function normalizePublicReference(int $tenantId, string $reference): string
    {
        $reference = trim($reference);
        if ($reference === '') {
            return '';
        }
        if (preg_match('/^file_[0-9a-f]{32}$/D', $reference) === 1) {
            $object = $this->deliverableObjectForTenant($tenantId, $reference);
            if ($object === null || $object['access_type'] !== 'public' || !StoragePurpose::allowsDocumentPreviewInheritance($object['purpose'])) {
                throw new \RuntimeException('素材对象不属于当前租户');
            }
            return $reference;
        }
        throw BusinessException::invalid('FILE_REFERENCE_INVALID', '仅允许稳定资源引用，不允许交付链接或外部地址');
    }

    /** File-internal metadata rollback only; absent from FileStorage and all HTTP actions. */
    public function discardFailedUpload(TenantContext|\PeanutAdmin\Kernel\Context\AuthenticatedMemberContext $context, array $stored): void
    {
        $key = $stored['file_key'] ?? '';
        $receipt = is_string($key) ? ($this->newUploads->{$key} ?? null) : null;
        $actor = $context instanceof TenantContext ? $this->execution->tenantAdmin() : $this->execution->member();
        if (!is_array($receipt) || $actor->tenantId !== $context->tenantId || $actor->memberId !== $context->memberId
            || $receipt['tenant_id'] !== $context->tenantId || $receipt['member_id'] !== $context->memberId
            || $receipt['request_id'] !== $this->execution->requestId()
            || $receipt['object_key'] !== ($stored['object_key'] ?? null)) {
            throw BusinessException::forbidden('FILE_UPLOAD_COMPENSATION_DENIED', '仅可补偿本次新上传');
        }
        unset($this->newUploads->{$key});
        $object = $this->objectForTenant($context->tenantId, $key);
        if ($object === null) {
            throw BusinessException::notFound('FILE_UPLOAD_COMPENSATION_NOT_FOUND', '上传资源不存在');
        }
        Db::transaction(function () use ($context, $key): void {
            $locked = $this->ownedObjects($context->tenantId)->where('file_key', $key)->lock(true)->find();
            $locked = is_array($locked) ? $locked : $locked?->toArray();
            if ($locked === null || (int) $locked['created_by_member_id'] !== $context->memberId || $locked['status'] !== 'ready'
                || \PeanutAdmin\Modules\File\Model\File::withTrashed()->where('file_key', $key)->find() !== null
                || FileReference::where('file_key', $key)->find() !== null
                || Db::name('file_derivative')->where('source_file_key|derivative_file_key', $key)->find() !== null) {
                throw BusinessException::conflict('FILE_UPLOAD_COMPENSATION_CONFLICT', '上传已被持久引用或状态改变');
            }
            if (!$this->archive($context->tenantId, $key)) {
                throw BusinessException::conflict('FILE_REVISION_CONFLICT', '上传状态已改变');
            }
        });
        try {
            $receipt = $this->drivers->make($object, $object)->delete((string) $object['object_key']);
            $metadata = ['provider_delete_acknowledged' => true, 'provider_outcome' => $receipt->outcome];
            $event = 'file.upload.compensated';
        } catch (\Throwable) {
            // Remain archived and non-deliverable; the original metadata error must survive.
            $metadata = ['provider_delete_acknowledged' => false, 'cleanup_pending' => true];
            $event = 'file.upload.compensation_pending';
        }
        if ($context instanceof TenantContext) {
            $this->audit->appendTenantMember($context, $event, 'file.upload.compensate', 'file', $key, null, null, 0, null, $metadata);
        } else {
            $this->audit->appendTenantSystem(
                $context->tenantId,
                $event,
                'file.upload.compensate',
                $context->requestId,
                [...$metadata, 'file_key' => $key, 'initiating_member_id' => $context->memberId],
            );
        }
    }

    public function delete(int $tenantId, string $fileKey): void
    {
        $object = $this->objectForTenant($tenantId, $fileKey);
        if ($object === null) {
            throw new \RuntimeException('文件对象不存在');
        }
        $context = $this->execution->tenantAdmin();
        $this->authorization->requireManagementPermission($context, 'peanut.file.delete');
        $this->authorization->authorize($context, $object, 'manage');
        Db::transaction(function () use ($fileKey, $context, $object): void {
            $locked = $this->find($this->ownedObjects($context->tenantId)->where('file_key', $fileKey)->lock(true));
            if ($locked === null || $locked['status'] !== 'ready' || $locked['revision'] != $object['revision']
                || $locked['object_key'] !== $object['object_key'] || $locked['storage_space_id'] != $object['storage_space_id']) {
                throw BusinessException::conflict('FILE_REFERENCE_OBJECT_CHANGED', '资源状态或存储绑定已改变');
            }
            if (FileReference::where('file_key', $fileKey)->lock(true)->find() !== null
                || FileImmutableSnapshot::where('file_key', $fileKey)->find() !== null
                || \PeanutAdmin\Modules\File\Model\Storage\FileDerivative::where('source_file_key', $fileKey)->find() !== null
                || \PeanutAdmin\Modules\File\Model\Storage\FileDerivative::where('derivative_file_key', $fileKey)->find() !== null) {
                throw BusinessException::conflict('FILE_REFERENCED', '资源仍被引用，不能删除');
            }
            $this->audit->appendTenantMember($context, 'file.delete.intent', 'file.manage', 'file', $fileKey);
            if (!$this->archive($context->tenantId, $fileKey)) {
                throw BusinessException::conflict('FILE_REVISION_CONFLICT', '资源状态已改变');
            }
        });
        try {
            $this->drivers->make($object, $object)->delete((string) $object['object_key']);
        } catch (\Throwable $error) {
            $this->restore($tenantId, $fileKey);
            $this->audit->appendTenantMember(
                $context,
                'file.delete.failed',
                'file.manage',
                'file',
                $fileKey,
                null,
                null,
                0,
                null,
                ['provider_delete_failed' => true],
            );
            throw $error;
        }
        $this->audit->appendTenantMember($context, 'file.delete.completed', 'file.manage', 'file', $fileKey);
    }

    public function accessUrlForTenant(int $tenantId, string $fileKey): string
    {
        $object = $this->deliverableObjectForTenant($tenantId, $fileKey);
        if ($object === null) {
            throw new \RuntimeException('文件对象不存在或不可用');
        }
        $context = $this->execution->tenantAdmin();
        $grant = $this->authorization->authorize($context, $object, 'download_original');
        return $this->url($object, $grant, 'download_original');
    }

    public function requireOriginalDownloadPermission(int $tenantId): void
    {
        $context = $this->execution->tenantAdmin();
        if ($context->tenantId !== $tenantId) {
            throw BusinessException::forbidden('FILE_ACCESS_DENIED', '无权访问其他租户资源');
        }
        $this->authorization->requireOriginalDownloadPermission($context);
    }

    public function attachmentSnapshot(TenantContext $context, string $fileKey): array
    {
        $actor = $this->execution->tenantAdmin();
        if ($actor->tenantId !== $context->tenantId || $actor->accountId !== $context->accountId || $actor->memberId !== $context->memberId
            || $actor->clientKey !== $context->clientKey || !hash_equals($actor->sessionKey, $context->sessionKey)) {
            throw BusinessException::forbidden('FILE_CONTEXT_MISMATCH', '资源与当前可信会话不一致');
        }
        if (preg_match('/^file_[0-9a-f]{32}$/D', $fileKey) !== 1) {
            throw BusinessException::invalid('FILE_KEY_INVALID', '资源引用无效');
        }
        $object = $this->deliverableObjectForTenant($context->tenantId, $fileKey);
        if ($object === null) {
            throw BusinessException::notFound('FILE_NOT_FOUND', '资源不存在');
        }
        $this->authorization->authorize($context, $object, 'preview');
        if (!is_string($object['original_name']) || strlen($object['original_name']) > 255
            || preg_match('/^[a-z0-9!#$&^_.+-]+\/[a-z0-9!#$&^_.+-]+$/D', (string) $object['media_type']) !== 1
            || strlen((string) $object['media_type']) > 127 || (int) $object['size_bytes'] < 0
            || preg_match('/^[0-9a-f]{64}$/D', (string) $object['sha256']) !== 1) {
            throw BusinessException::conflict('FILE_ATTACHMENT_METADATA_INVALID', '资源登记元数据无效');
        }
        return ['file_key' => $fileKey, 'original_name' => $object['original_name'], 'verified_mime_type' => $object['media_type'],
            'size_bytes' => (int) $object['size_bytes'], 'sha256' => $object['sha256']];
    }

    public function describeForDocument(TenantContext $context, string $fileKey): array
    {
        $object = $this->deliverableObjectForTenant($context->tenantId, $fileKey);
        if ($object === null) {
            throw BusinessException::notFound('FILE_NOT_FOUND', '资源不存在');
        }
        $this->authorization->authorize($context, $object, 'preview');
        return ['file_key' => $fileKey,'verified_mime_type' => (string) $object['media_type'],'sha256' => (string) $object['sha256'],'revision' => (int) $object['revision']];
    }

    public function assertDocumentSnapshotReadable(TenantContext $context, int $documentId, string $fileKey, string $sha256, string $verifiedMimeType): void
    {
        $actor = $this->execution->tenantAdmin();
        if ($actor->tenantId !== $context->tenantId || $actor->accountId !== $context->accountId || $actor->memberId !== $context->memberId
            || $actor->clientKey !== $context->clientKey || !hash_equals($actor->sessionKey, $context->sessionKey)) {
            throw BusinessException::forbidden('FILE_REFERENCE_CONTEXT_MISMATCH', '引用与当前可信会话不一致');
        }
        if (!(($pdo = Db::connect()->getPdo()) instanceof \PDO) || !$pdo->inTransaction()) {
            throw BusinessException::conflict('FILE_REFERENCE_TRANSACTION_REQUIRED', '历史快照复核必须在业务事务内');
        }
        $object = $this->lockedReferenceObject($context, $fileKey);
        $capture = FileImmutableSnapshot::where('file_key', $fileKey)->lock(true)->find();
        if ($capture === null || $capture->status !== 'claimed' || (int) $capture->claimed_document_id !== $documentId
            || !in_array($object['purpose'], ['document.snapshot', 'document.snapshot-sensitive'], true) || $object['access_type'] !== 'private'
            || !hash_equals($sha256, (string) $object['sha256']) || !hash_equals($sha256, (string) $capture->source_sha256)
            || $verifiedMimeType !== $object['media_type'] || $verifiedMimeType !== $capture->verified_mime_type) {
            throw BusinessException::conflict('FILE_DOCUMENT_SNAPSHOT_CHANGED', '不可变文件快照与文档版本不一致');
        }
        $this->authorization->authorizeDocumentReference($context, $object, $documentId);
    }

    /** File-internal reference locking/enrichment; never exposed by a cross-module or HTTP contract. */
    public function lockedReferenceObject(TenantContext $context, string $fileKey): array
    {
        return $this->lockedAcceptedReferenceObject($context->tenantId, $fileKey);
    }

    /** File-internal structural read under the caller's accepted-write proof and File object lock. */
    public function lockedAcceptedReferenceObject(int $logicalTenantId, string $fileKey): array
    {
        if ($this->execution->tenantId() !== $logicalTenantId) {
            throw BusinessException::forbidden('FILE_REFERENCE_TENANT_MISMATCH', '资源引用与当前租户不一致');
        }
        $tenantId = $this->logicalTenantId($logicalTenantId);
        $locked = $this->find($this->ownedObjects($tenantId)->where('file_key', $fileKey)->lock(true));
        if ($locked === null || $locked['status'] !== 'ready') {
            throw BusinessException::notFound('FILE_REFERENCE_NOT_FOUND', '引用资源不存在');
        }
        $object = $this->deliverableObjectForTenant($tenantId, $fileKey);
        if ($object === null) {
            throw BusinessException::notFound('FILE_REFERENCE_NOT_FOUND', '引用资源不存在');
        }
        // Enrichment may use an older RR snapshot; every locked File field remains authoritative.
        $object = [...$object, ...$locked];
        if (!$this->dataScopePolicy->usesTenantColumn()) {
            $object['tenant_id'] = $tenantId;
        }
        return $object;
    }

    public function captureForDocument(TenantContext $context, string $fileKey, string $expectedSha256, int $expectedRevision, string $captureId): array
    {
        if (($pdo = Db::connect()->getPdo()) instanceof \PDO && $pdo->inTransaction()) {
            throw BusinessException::conflict('FILE_SNAPSHOT_OUTER_TRANSACTION_DENIED', '文件捕获必须在业务事务之前持久完成');
        }
        if (preg_match('/^[0-9a-f]{64}$/D', $expectedSha256) !== 1 || $expectedRevision < 1 || $captureId === '' || strlen($captureId) > 256) {
            throw BusinessException::invalid('FILE_SNAPSHOT_INPUT_INVALID', '文件快照输入无效');
        }
        $captureId = hash('sha256', $captureId);
        $stored = null;
        $opened = null;
        try {
            $plan = Db::transaction(function () use ($context, $fileKey, $expectedSha256, $expectedRevision, $captureId): array {
                $this->ownedObjects($context->tenantId)->where('file_key', $fileKey)->lock(true)->find();
                $source = $this->deliverableObjectForTenant($context->tenantId, $fileKey);
                if ($source === null || (int) $source['revision'] !== $expectedRevision || !hash_equals($expectedSha256, (string) $source['sha256'])) {
                    throw BusinessException::conflict('FILE_SNAPSHOT_SOURCE_CHANGED', '快照来源已改变');
                }
                $this->authorization->authorize($context, $source, 'download_original');
                $existing = FileImmutableSnapshot::where('capture_id', $captureId)->lock(true)->find();
                if ($existing !== null && $existing->status === 'abandoned') {
                    if ($existing->source_file_key !== $fileKey || (int) $existing->source_revision !== $expectedRevision || !hash_equals($expectedSha256, (string) $existing->source_sha256)) {
                        throw BusinessException::conflict('FILE_SNAPSHOT_IDEMPOTENCY_CONFLICT', '文件快照请求已被其他内容使用');
                    }
                    // Only an acknowledged, unclaimed cleanup may retry with a newly generated private object.
                    $existing->delete();
                    $existing = null;
                }
                if ($existing !== null) {
                    $target = $this->deliverableObjectForTenant($context->tenantId, (string) $existing['file_key']);
                    if ($existing['source_file_key'] !== $fileKey || (int) $existing['source_revision'] !== $expectedRevision || !in_array($existing['status'], ['captured','claimed'], true) || !hash_equals($expectedSha256, (string) $existing['source_sha256']) || $target === null || $target['access_type'] !== 'private' || !hash_equals($expectedSha256, (string) $target['sha256'])) {
                        throw BusinessException::conflict('FILE_SNAPSHOT_IDEMPOTENCY_CONFLICT', '文件快照请求已被其他内容使用');
                    }
                    return ['existing' => ['file_key' => $existing['file_key'],'verified_mime_type' => $existing['verified_mime_type'],'sha256' => $existing['source_sha256'],'source_file_key' => $fileKey,'capture_id' => $captureId]];
                }
                return ['source' => $source];
            });
            if (isset($plan['existing'])) {
                return $plan['existing'];
            }
            // The File object ledger commits before provider IO. A crash leaves a discoverable pending_write/ready snapshot object.
            $source = $plan['source'];
            $opened = $this->materialize($source);
            $purpose = StoragePurpose::allowsDocumentPreviewInheritance($source['purpose']) ? 'document.snapshot' : 'document.snapshot-sensitive';
            $stored = $this->storePath($context->tenantId, $context->memberId, $purpose, $opened['path'], (string) $source['original_name'], (string) $source['media_type']);
            return Db::transaction(function () use ($context, $fileKey, $expectedSha256, $expectedRevision, $captureId, $stored, $source): array {
                $this->ownedObjects($context->tenantId)->where('file_key', $fileKey)->lock(true)->find();
                $current = $this->deliverableObjectForTenant($context->tenantId, $fileKey);
                if ($current === null || (int) $current['revision'] !== $expectedRevision || !hash_equals($expectedSha256, (string) $current['sha256'])) {
                    throw BusinessException::conflict('FILE_SNAPSHOT_SOURCE_CHANGED', '捕获期间来源已改变');
                }
                $this->authorization->authorize($context, $current, 'download_original');
                $this->ownedObjects($context->tenantId)->where('file_key', $stored['file_key'])->lock(true)->find();
                $target = $this->deliverableObjectForTenant($context->tenantId, $stored['file_key']);
                if ($target === null || !hash_equals($expectedSha256, (string) $target['sha256']) || $target['media_type'] !== $source['media_type']) {
                    throw BusinessException::conflict('FILE_SNAPSHOT_BYTES_CHANGED', '快照字节与来源不一致');
                }
                FileImmutableSnapshot::create(['file_key' => $stored['file_key'],'tenant_id' => $context->tenantId,'capture_id' => $captureId,'source_file_key' => $fileKey,'source_revision' => $expectedRevision,'source_sha256' => $expectedSha256,'verified_mime_type' => $target['media_type'],'status' => 'captured','created_by_member_id' => $context->memberId,'created_at' => gmdate('Y-m-d H:i:s')]);
                $this->audit->appendTenantMember($context, 'file.snapshot.created', 'file.snapshot.capture', 'file', $stored['file_key'], null, null, 1, $expectedSha256);
                return ['file_key' => $stored['file_key'],'verified_mime_type' => $target['media_type'],'sha256' => $expectedSha256,'source_file_key' => $fileKey,'capture_id' => $captureId];
            });
        } catch (\Throwable $error) {
            if (is_array($stored)) {
                try {
                    $this->abandonDocumentSnapshot($context, ['file_key' => $stored['file_key'],'capture_id' => $captureId]);
                } catch (\Throwable) { /* Durable File ledger remains recoverable; preserve the original business error. */
                }
            }
            throw $error;
        } finally {
            if (is_array($opened) && $opened['temporary']) {
                @unlink($opened['path']);
            }
        }
    }

    public function abandonDocumentSnapshot(TenantContext $context, array $snapshot): void
    {
        $key = $snapshot['file_key'] ?? '';
        $proof = is_string($key) ? ($this->newUploads->{$key} ?? null) : null;
        if (!is_array($proof) || $proof['tenant_id'] !== $context->tenantId || $proof['member_id'] !== $context->memberId || $proof['request_id'] !== $this->execution->requestId()) {
            throw BusinessException::forbidden('FILE_SNAPSHOT_ABANDON_DENIED', '仅可补偿本次未认领文件快照');
        }
        $this->cleanupDocumentSnapshot($context, $key, false, $snapshot['capture_id'] ?? null);
        unset($this->newUploads->{$key});
    }

    public function claimDocumentSnapshot(TenantContext $context, int $documentId, array $snapshot): void
    {
        if (!(($pdo = Db::connect()->getPdo()) instanceof \PDO) || !$pdo->inTransaction()) {
            throw BusinessException::conflict('FILE_SNAPSHOT_CLAIM_TRANSACTION_REQUIRED', '认领必须在业务外层事务内');
        }
        $key = $snapshot['file_key'] ?? '';
        $owner = $this->documents->requirePolicy()->referencesForUpdate($context, $documentId);
        if (!in_array($key, $owner['file_keys'], true) || $owner['authorization_revision'] === '' || $owner['reference_digest'] === '') {
            throw BusinessException::forbidden('FILE_SNAPSHOT_CLAIM_DENIED', '快照不在当前受权业务引用中');
        }
        $object = $this->ownedObjects($context->tenantId)->where('file_key', $key)->lock(true)->find();
        $capture = FileImmutableSnapshot::where('file_key', $key)->lock(true)->find();
        if ($object === null || $capture === null || $capture->capture_id !== ($snapshot['capture_id'] ?? null)
            || !hash_equals((string) $capture->source_sha256, (string) ($snapshot['sha256'] ?? ''))
            || !in_array($capture->status, ['captured','claimed'], true) || ($capture->status === 'claimed' && (int) $capture->claimed_document_id !== $documentId)
            || FileReference::where('file_key', $key)->where('owner_module_key', 'peanut.document')->where('owner_type', 'document')->where('owner_id', (string) $documentId)->find() === null) {
            throw BusinessException::conflict('FILE_SNAPSHOT_CLAIM_DENIED', '不可认领未受权或已回收的快照');
        }
        $object = $this->deliverableObjectForTenant($context->tenantId, $key);
        if ($object === null) {
            throw BusinessException::conflict('FILE_SNAPSHOT_CLAIM_DENIED', '快照字节不可用');
        }
        $this->authorization->authorizeDocumentReference($context, $object, $documentId);
        $capture->save(['status' => 'claimed','claimed_document_id' => $documentId,'claimed_at' => gmdate('Y-m-d H:i:s')]);
        $this->audit->appendTenantMember($context, 'file.snapshot.claimed', 'file.snapshot.claim', 'file', $key, null, null, 1, (string) $capture->source_sha256, ['document_id' => $documentId]);
    }

    public function pendingDocumentSnapshots(TenantContext $context): array
    {
        $this->authorization->requireManagementPermission($context, 'peanut.file.delete');
        $objects = $this->ownedObjects($context->tenantId)->whereIn('purpose', ['document.snapshot','document.snapshot-sensitive'])->where('created_at', '<', gmdate('Y-m-d H:i:s', time() - 3600))->order('created_at')->limit(100)->select()->toArray();
        return array_values(array_filter(array_map(function (array $object): ?array {
            $capture = FileImmutableSnapshot::where('file_key', $object['file_key'])->find();
            if ($capture?->status === 'claimed' || $capture?->status === 'abandoned' || FileReference::where('file_key', $object['file_key'])->find() !== null) {
                return null;
            }
            return ['file_key' => $object['file_key'],'status' => $capture?->status ?? 'interrupted_capture','created_at' => $object['created_at']];
        }, $objects)));
    }

    public function recoverDocumentSnapshot(TenantContext $context, string $fileKey): array
    {
        $this->authorization->requireManagementPermission($context, 'peanut.file.delete');
        return $this->cleanupDocumentSnapshot($context, $fileKey, true, null);
    }

    /** Archive under the same object lock as claim/reference/delete, then record the real provider outcome. */
    private function cleanupDocumentSnapshot(TenantContext $context, string $key, bool $recovery, ?string $captureId): array
    {
        if (($pdo = Db::connect()->getPdo()) instanceof \PDO && $pdo->inTransaction()) {
            throw BusinessException::conflict('FILE_SNAPSHOT_CLEANUP_TRANSACTION_DENIED', '回收必须在业务事务之外');
        }
        $object = Db::transaction(function () use ($context, $key, $recovery, $captureId): array {
            $object = $this->ownedObjects($context->tenantId)->where('file_key', $key)->lock(true)->find();
            $object = is_array($object) ? $object : $object?->toArray();
            $capture = FileImmutableSnapshot::where('file_key', $key)->lock(true)->find();
            if ($object === null || !in_array($object['purpose'], ['document.snapshot','document.snapshot-sensitive'], true) || $object['access_type'] !== 'private'
                || (!$recovery && (int) $object['created_by_member_id'] !== $context->memberId)
                || ($recovery && strtotime($object['created_at'] . ' UTC') > time() - 3600)
                || $capture?->status === 'claimed' || ($capture !== null && !$recovery && $capture->capture_id !== $captureId)
                || FileReference::where('file_key', $key)->find() !== null
                || \PeanutAdmin\Modules\File\Model\File::withTrashed()->where('file_key', $key)->find() !== null
                || Db::name('file_derivative')->where('source_file_key|derivative_file_key', $key)->find() !== null) {
                throw BusinessException::conflict('FILE_SNAPSHOT_RECOVERY_DENIED', '快照已被认领、仍被引用或尚未进入恢复窗口');
            }
            $this->ownedObjects($context->tenantId)->where('file_key', $key)->update(['status' => 'archived','archived_at' => new Raw('UTC_TIMESTAMP(3)'),'updated_at' => new Raw('UTC_TIMESTAMP(3)'),'revision' => new Raw('revision+1')]);
            $capture?->save(['status' => 'cleanup_pending']);
            $space = StorageSpace::where('id', $object['storage_space_id'])->lock(true)->find();
            if ($space === null) {
                throw BusinessException::conflict('FILE_SNAPSHOT_RECOVERY_CONFIG_MISSING', '快照存储空间不存在');
            }
            StorageAccount::where('id', $space->account_id)->lock(true)->find();
            $configured = $this->objectForTenant($context->tenantId, $key, false);
            if ($configured === null) {
                throw BusinessException::conflict('FILE_SNAPSHOT_RECOVERY_CONFIG_MISSING', '快照原存储配置不可用');
            }
            $this->audit->appendTenantMember($context, 'file.snapshot.cleanup_intent', 'file.snapshot.recover', 'file', $key);
            return $configured;
        });
        try {
            $receipt = $this->drivers->make($object, $object)->delete((string) $object['object_key']);
            FileImmutableSnapshot::where('file_key', $key)->update(['status' => 'abandoned']);
            $result = ['file_key' => $key,'cleanup_pending' => false,'provider_delete_acknowledged' => true,'provider_outcome' => $receipt->outcome];
        } catch (\Throwable) {
            $result = ['file_key' => $key,'cleanup_pending' => true,'provider_delete_acknowledged' => false];
        }
        $this->audit->appendTenantMember($context, 'file.snapshot.cleanup_result', 'file.snapshot.recover', 'file', $key, null, null, 0, null, $result);
        return $result;
    }

    /** @return array{path:string,filename:string,media_type:string,temporary:bool} */
    public function openForTenant(int $tenantId, string $fileKey): array
    {
        $object = $this->deliverableObjectForTenant($tenantId, $fileKey);
        if ($object === null) {
            throw BusinessException::notFound('STORAGE_INPUT_NOT_FOUND', '文件不存在或不可用');
        }
        $this->authorization->authorize($this->execution->tenantAdmin(), $object, 'download_original');
        return [
            ...$this->materialize($object),
            'filename' => (string) $object['original_name'],
            'media_type' => (string) $object['media_type'],
        ];
    }

    /** @return array{path:string,filename:string,media_type:string,disposition:string,temporary:bool} */
    public function authorizedDownload(int $tenantId, string $fileKey, string $token): array
    {
        $object = $this->deliverableObjectForTenant($tenantId, $fileKey);
        if ($object === null) {
            throw BusinessException::notFound('STORAGE_DELIVERY_NOT_FOUND', '文件不存在或不可用');
        }
        try {
            $claims = $this->deliveryTokens->verifyAndConsume(
                $token,
                $tenantId,
                $fileKey,
                new DateTimeImmutable('now', new DateTimeZone('UTC')),
            );
        } catch (\Throwable) {
            throw new BusinessException('STORAGE_DELIVERY_INPUT_INVALID', 422, '文件链接无效或已过期');
        }
        $expectedVisibility = $object['access_type'] === 'private'
            ? DeliveryVisibility::Private
            : DeliveryVisibility::Public;
        if ($claims['visibility'] !== $expectedVisibility) {
            throw new BusinessException('STORAGE_DELIVERY_INPUT_INVALID', 422, '文件链接无效或已过期');
        }
        $grant = Db::name('file_delivery_grant')->where('token_id_hash', hash('sha256', $claims['token_id']))->find();
        if (!is_array($grant) || (int) $grant['subject_tenant_id'] !== $tenantId
            || $grant['file_key'] !== $fileKey || (int) $grant['file_revision'] !== (int) $object['revision']
            || $grant['visibility'] !== $object['access_type']
            || strtotime((string) $grant['expires_at'] . ' UTC') <= time()) {
            throw BusinessException::forbidden('FILE_DELIVERY_REVOKED', '资源链接已失效');
        }
        $source = $this->deliverableObjectForTenant($tenantId, (string) $grant['source_file_key']);
        if ($source === null || (int) $source['revision'] !== (int) $grant['source_revision']
            || $source['access_type'] !== $grant['source_visibility']) {
            throw BusinessException::forbidden('FILE_DELIVERY_REVOKED', '资源链接已失效');
        }
        if ($grant['operation'] === 'public') {
            if (!hash_equals((string) $grant['authorization_revision'], $this->authorization->authorizePublic($source))) {
                throw BusinessException::forbidden('FILE_DELIVERY_REVOKED', '资源授权已改变');
            }
            $this->assertPublicDerivative($source);
        } else {
            $subject = json_decode((string) $grant['subject_json'], true, 16, JSON_THROW_ON_ERROR);
            $context = $this->authorization->grantContext($subject);
            if ($context->authorizationRevision !== (int) $subject['authorization_revision']) {
                throw BusinessException::forbidden('FILE_DELIVERY_REVOKED', '资源授权已改变');
            }
            $live = $this->authorization->authorize($context, $source, (string) $grant['operation'], $grant['document_id'] === null ? null : (int) $grant['document_id']);
            if (!hash_equals((string) $grant['authorization_revision'], $live['authorization_revision'])
                || $grant['reference_digest'] !== $live['reference_digest']) {
                throw BusinessException::forbidden('FILE_DELIVERY_REVOKED', '资源授权已改变');
            }
        }
        return [
            ...$this->materialize($object),
            'filename' => (string) $object['original_name'],
            'media_type' => (string) $object['media_type'],
            'disposition' => $grant['operation'] !== 'download_original'
                && in_array(strtolower((string) $object['media_type']), ['image/png', 'image/jpeg', 'image/webp', 'image/gif', 'audio/mpeg', 'audio/ogg', 'audio/wav', 'video/mp4', 'video/webm', 'video/ogg'], true)
                ? 'inline' : 'attachment',
        ];
    }

    /** The File-owned facade returns no storage account, credential or physical address. */
    public function resolveAuthorized(TenantContext $context, string $fileKey, string $operation, ?int $documentId = null, ?string $variantKey = null, ?string $viewerAdapterId = null): array
    {
        $source = $this->deliverableObjectForTenant($context->tenantId, $fileKey);
        if ($source === null) {
            throw BusinessException::notFound('FILE_NOT_FOUND', '资源不存在');
        }
        $grant = $this->authorization->authorize($context, $source, $operation, $documentId);
        $canDownload = false;
        try {
            $this->authorization->authorize($context, $source, 'download_original');
            $canDownload = true;
        } catch (BusinessException) {
            // Preview never inherits an independent original-download right.
        }
        if ($operation === 'manage') {
            return ['file_key' => $fileKey, 'revision' => (int) $source['revision'], 'can_manage' => true];
        }
        $target = $source;
        $selectedVariantKey = null;
        if ($operation === 'preview' && (!$canDownload || $variantKey !== null)) {
            $query = Db::name('file_derivative')->where('source_file_key', $fileKey);
            if ($this->dataScopePolicy->usesTenantColumn()) {
                $query->where('tenant_id', $context->tenantId);
            }
            if ($variantKey !== null) {
                $query->where('variant_key', $variantKey);
            }
            $variant = $query->order('variant_key')->find();
            $target = is_array($variant) ? $this->deliverableObjectForTenant($context->tenantId, (string) $variant['derivative_file_key']) : null;
            if ($target === null || $target['file_key'] === $fileKey || $target['sha256'] === $source['sha256']
                || !in_array($target['media_type'], ['image/png', 'image/jpeg', 'image/webp', 'application/pdf'], true)) {
                return ['file_key' => $fileKey, 'state' => 'preview_unavailable', 'can_download_original' => $canDownload, 'url' => null];
            }
            $selectedVariantKey = (string) $variant['variant_key'];
        }
        $capability = $this->capabilities->resolve(new \PeanutAdmin\Modules\ContentTools\Contract\AuthorizedContentContext(
            'file_resource',
            (string) $target['media_type'],
            true,
            false,
            $canDownload,
            $grant['authorization_revision'],
            'browser',
            $this->authorization->contentProviderModules($context),
        ), 'view', $viewerAdapterId, null);
        if ($operation === 'preview' && !$capability['canView']) {
            return ['file_key' => $fileKey, 'state' => $capability['state'], 'capabilities' => $capability, 'can_download_original' => $canDownload, 'url' => null];
        }
        return [
            'file_key' => $fileKey, 'delivery_file_key' => (string) $target['file_key'], 'source_file_key' => $fileKey,
            'source_sha256' => (string) $source['sha256'], 'media_type' => (string) $target['media_type'],
            'original_name' => (string) $source['original_name'], 'revision' => (int) $source['revision'],
            'state' => 'available', 'purpose' => $operation === 'preview' ? 'preview' : 'download_original',
            'bytes' => $target['file_key'] === $fileKey ? 'original' : 'derivative',
            'variant_key' => $selectedVariantKey,
            'can_download_original' => $canDownload, 'authorization_revision' => $grant['authorization_revision'],
            'capabilities' => $capability,
            'expires_at' => (time() + 300) * 1000,
            'url' => $this->url($target, $grant, $operation, $source, $documentId),
        ];
    }

    /** Server-side derivative binding inspects actual bytes under current management authorization. */
    public function verifyImageDerivative(TenantContext $context, string $fileKey, int $width, int $height, string $mediaType): void
    {
        $object = $this->deliverableObjectForTenant($context->tenantId, $fileKey);
        if ($object === null) {
            throw BusinessException::notFound('FILE_DERIVATIVE_NOT_FOUND', '衍生资源不存在');
        }
        $this->authorization->authorize($context, $object, 'manage');
        $materialized = $this->materialize($object);
        try {
            $metadata = (new \PeanutAdmin\FileMedia\Media\ImageMetadataInspector())->inspect($materialized['path']);
            if ($metadata->width !== $width || $metadata->height !== $height || $metadata->mediaType !== $mediaType) {
                throw BusinessException::invalid('FILE_DERIVATIVE_CONTENT_MISMATCH', '衍生图内容与声明不一致');
            }
        } finally {
            if ($materialized['temporary']) {
                @unlink($materialized['path']);
            }
        }
    }

    /** @return array{path:string,temporary:bool} */
    private function materialize(array $object): array
    {
        $driver = $this->drivers->make($object, $object);
        $path = $driver->localPath((string) $object['object_key']);
        $temporary = false;
        if ($path === null) {
            $path = tempnam(sys_get_temp_dir(), 'peanut-storage-open-');
            if (!is_string($path)) {
                throw new \RuntimeException('文件临时空间不可用');
            }
            $temporary = true;
            try {
                $driver->downloadTo((string) $object['object_key'], $path);
            } catch (\Throwable $error) {
                if (is_file($path)) {
                    unlink($path);
                }
                throw $error;
            }
        }
        if (!is_file($path) || !is_readable($path)) {
            if ($temporary && is_file($path)) {
                unlink($path);
            }
            throw BusinessException::notFound('STORAGE_DELIVERY_NOT_FOUND', '文件不存在或不可用');
        }
        if (!hash_equals((string) $object['sha256'], (string) hash_file('sha256', $path))) {
            if ($temporary) {
                unlink($path);
            }
            throw BusinessException::conflict('FILE_BYTES_CHANGED', '资源内容与登记不一致');
        }
        return ['path' => $path, 'temporary' => $temporary];
    }

    /** File-owned confirmation, domain separated from delivery tokens. No delivery URL is persisted. */
    public function visibilityConfirmation(array $payload): string
    {
        return hash_hmac('sha256', "file.visibility.confirmation.v1\0" . self::visibilityJson($payload), $this->signingSecret);
    }

    /** Complete managed source/derivative closure. Call again under object locks before accepting the batch. */
    public function visibilityManifest(TenantContext $context, array $roots, string $batchKey, bool $lock = false, array $qualifications = []): array
    {
        $this->authorization->requireManagementPermission($context, 'peanut.file.visibility.change');
        if ($roots === [] || count($roots) > 100 || count(array_unique($roots)) !== count($roots)
            || preg_match('/^batch_[a-f0-9]{32}$/D', $batchKey) !== 1) {
            throw BusinessException::invalid('FILE_VISIBILITY_INPUT_INVALID', '请选择有效资源');
        }
        foreach ($roots as $key) {
            if (!is_string($key) || preg_match('/^file_[a-f0-9]{32}$/D', $key) !== 1) {
                throw BusinessException::invalid('FILE_VISIBILITY_INPUT_INVALID', '资源标识无效');
            }
        }
        sort($roots, SORT_STRING);
        $keys = $roots;
        do {
            $oldKeys = $keys;
            $links = \PeanutAdmin\Modules\File\Model\Storage\FileDerivative::where([])
                ->where(function (BaseQuery $query) use ($keys): void {
                    $query->whereIn('source_file_key', $keys)->whereOr('derivative_file_key', 'in', $keys);
                })->order(['source_file_key' => 'asc', 'variant_key' => 'asc'])->select()->toArray();
            foreach ($links as $link) {
                $keys[] = (string) $link['source_file_key'];
                $keys[] = (string) $link['derivative_file_key'];
            }
            $keys = array_values(array_unique($keys));
            sort($keys, SORT_STRING);
            if (count($keys) > 100) {
                throw BusinessException::invalid('FILE_VISIBILITY_CLOSURE_LIMIT', '关联资源过多，请缩小批次');
            }
        } while ($oldKeys !== $keys);
        $files = [];
        foreach ($keys as $key) {
            if ($lock) {
                $row = $this->ownedObjects($context->tenantId)->where('file_key', $key)->lock(true)->find();
                if ($row === null) {
                    throw BusinessException::notFound('FILE_VISIBILITY_OBJECT_NOT_FOUND', '资源不存在');
                }
            }
            $object = $this->objectForTenant($context->tenantId, $key, false);
            if ($object === null || $object['status'] !== 'ready' || $object['access_type'] !== 'public'
                || ($object['visibility_batch_key'] ?? null) !== null || !is_string($object['sha256'])
                || preg_match('/^[a-f0-9]{64}$/D', $object['sha256']) !== 1) {
                throw BusinessException::conflict('FILE_VISIBILITY_OBJECT_NOT_STABLE', '资源不可切换或已被其他操作占用');
            }
            $this->authorization->authorize($context, $object, 'manage');
            $target = $this->route((string) $object['purpose'], 'private');
            if ($target['driver'] !== 'local' || $target['bucket'] !== null || $target['access_domain'] !== null
                || $target['local_path'] !== 'private/storage') {
                throw BusinessException::conflict('FILE_VISIBILITY_ORIGIN_UNQUALIFIED', '目标不支持可核验的私有存储');
            }
            if ($object['driver'] === 'local') {
                if ($object['bucket'] !== null || $object['access_domain'] !== null || $object['local_path'] !== 'public/storage') {
                    throw BusinessException::conflict('FILE_VISIBILITY_LOCATION_INVALID', '公开和私有存储空间不匹配');
                }
            } elseif ($object['driver'] !== 'qiniu'
                || !\PeanutAdmin\Modules\File\Infrastructure\Storage\QiniuS3DownloadTransport::usesS3Domain((string) $object['access_domain'])) {
                throw BusinessException::conflict('FILE_VISIBILITY_ORIGIN_UNQUALIFIED', '当前源站的直链、历史版本或缓存关闭能力尚未确认');
            }
            $source = array_intersect_key($object, array_flip(['file_key','tenant_id','purpose','access_type','storage_space_id',
                'object_key','original_name','media_type','size_bytes','sha256','revision','driver','local_path','space_key']));
            foreach (['tenant_id','storage_space_id','size_bytes','revision'] as $field) {
                $source[$field] = (string) $source[$field];
            }
            if ($object['driver'] === 'qiniu') {
                foreach (['account_key','bucket','region','endpoint','access_domain','credential_key_version'] as $field) {
                    $source[$field] = $object[$field];
                }
                $source['account_id'] = (string) $object['account_id'];
                $source['credential_sha256'] = hash('sha256', (string) $object['credential_ciphertext']);
                if (isset($qualifications[$key])) {
                    $source['origin_qualification'] = $qualifications[$key];
                }
            }
            $destination = ['storage_space_id' => (string) $target['space_id'], 'space_key' => $target['space_key'],
                'object_key' => $this->ownerPrefix($context->tenantId) . 'visibility/' . $batchKey . '/' . $key,
                'access_type' => 'private', 'driver' => 'local', 'local_path' => 'private/storage'];
            $driver = $this->drivers->make($target, $target);
            if ($driver->inspect($destination['object_key'])->exists) {
                throw BusinessException::conflict('FILE_VISIBILITY_TARGET_EXISTS', '目标已存在，请重新预检查');
            }
            $files[$key] = ['source' => $source, 'target' => $destination];
        }
        $references = FileReference::where([])->whereIn('file_key', $keys)
            ->order(['file_key' => 'asc', 'owner_module_key' => 'asc', 'owner_type' => 'asc', 'owner_id' => 'asc'])
            ->field('file_key,owner_module_key,owner_type,owner_id,reference_digest')->select()->toArray();
        return ['roots' => $roots, 'files' => $files, 'derivatives_sha256' => hash('sha256', self::visibilityJson($links)),
            'registered_reference_count' => count($references), 'references_sha256' => hash('sha256', self::visibilityJson($references)),
            'known_cdn' => [], 'external_usage_complete' => false,
            'warning' => '包含关联的源文件和衍生文件；公开页面可能断图。无法穷举外部直链，也无法收回已下载或第三方缓存的副本。'];
    }

    /** Provider metadata is read before the short admission transaction, never while its row locks are held. */
    public function visibilityOriginQualifications(TenantContext $context, array $manifest): array
    {
        $this->authorization->requireManagementPermission($context, 'peanut.file.visibility.change');
        $qualifications = [];
        $observedOrigins = [];
        foreach ($manifest['files'] as $key => $file) {
            if ($file['source']['driver'] === 'qiniu') {
                $source = $file['source'];
                $location = $this->visibilityLocation($source);
                $identity = hash('sha256', self::visibilityJson(array_intersect_key($source, array_flip([
                    'account_id','account_key','credential_sha256','credential_key_version','bucket','region','endpoint','access_domain',
                ]))));
                $observedOrigins[$identity] ??= $this->drivers->qiniuVisibilityOrigin($location)->observe();
                $object = $this->drivers->make($location, $location)->inspect($source['object_key']);
                if (!$object->exists || $object->sizeBytes !== (int) $source['size_bytes']
                    || $object->mediaType !== $source['media_type'] || !is_string($object->etag) || $object->etag === ''
                    || $object->versionId !== null) {
                    throw BusinessException::conflict('FILE_VISIBILITY_SOURCE_CHANGED', '源文件的物理状态与登记不一致，整批尚未开始');
                }
                // The provider ETag fences a revision; downloaded bytes are independently checked against the real SHA-256.
                $qualifications[$key] = ['origin' => $observedOrigins[$identity],
                    'object' => ['etag' => $object->etag, 'size_bytes' => $object->sizeBytes,
                        'media_type' => $object->mediaType, 'version_id' => null]];
            }
        }
        return $qualifications;
    }

    /** One repeatable physical step. Unknown outcomes inspect the same frozen source and protected target. */
    public function visibilityStorageStep(TenantContext $context, string $batchKey, array $source, array $target, string $step): array
    {
        $this->authorization->requireManagementPermission($context, 'peanut.file.visibility.change');
        $key = $source['file_key'] ?? '';
        $object = $this->objectForTenant($context->tenantId, $key, false);
        if ($object === null || ($object['visibility_batch_key'] ?? null) !== $batchKey
            || !in_array($object['status'], ['restricting','verifying','restricted_attention'], true)
            || (int) ($source['tenant_id'] ?? 0) !== $context->tenantId) {
            throw BusinessException::conflict('FILE_VISIBILITY_OPERATION_CHANGED', '资源操作已改变');
        }
        $old = $this->visibilityLocation($source);
        $new = $this->visibilityLocation($target);
        $sourceDriver = $this->drivers->make($old, $old);
        $targetDriver = $this->drivers->make($new, $new);
        $origin = null;
        if ($old['driver'] === 'qiniu') {
            $origin = $this->drivers->qiniuVisibilityOrigin($old);
            if (($source['origin_qualification']['origin'] ?? null) !== $origin->observe()) {
                throw BusinessException::conflict('FILE_VISIBILITY_ORIGIN_CHANGED', '源站或缓存配置已改变，保留私有副本等待处理');
            }
        }
        $validate = static function (string $path) use ($source): void {
            if (!is_file($path) || is_link($path) || filesize($path) !== (int) $source['size_bytes']
                || !hash_equals($source['sha256'], (string) hash_file('sha256', $path))
                || (new \finfo(FILEINFO_MIME_TYPE))->file($path) !== $source['media_type']) {
                throw BusinessException::conflict('FILE_VISIBILITY_BYTES_CHANGED', '资源内容与原登记不一致');
            }
        };
        $validateObservation = static function (\PeanutAdmin\FileMedia\Storage\StorageObjectObservation $observation) use ($source): void {
            if ($source['driver'] === 'qiniu'
                && (!$observation->exists || $observation->etag !== ($source['origin_qualification']['object']['etag'] ?? null)
                    || $observation->sizeBytes !== (int) $source['size_bytes']
                    || $observation->mediaType !== $source['media_type'] || $observation->versionId !== null)) {
                throw BusinessException::conflict('FILE_VISIBILITY_SOURCE_CHANGED', '原对象版本已改变，保留受保护副本等待处理');
            }
        };
        $newPath = $targetDriver->localPath($target['object_key']);
        if (!is_string($newPath)) {
            throw BusinessException::conflict('FILE_VISIBILITY_ORIGIN_UNQUALIFIED', '目标不支持安全核验');
        }
        if ($step === 'copy') {
            if (!$targetDriver->inspect($target['object_key'])->exists) {
                if ($old['driver'] === 'qiniu') {
                    $validateObservation($sourceDriver->inspect($source['object_key']));
                }
                $materialized = $this->materialize($source + $old);
                try {
                    $validate($materialized['path']);
                    $targetDriver->put($target['object_key'], $materialized['path']);
                } finally {
                    if ($materialized['temporary'] && is_file($materialized['path'])) {
                        unlink($materialized['path']);
                    }
                }
            }
            $validate($newPath);
            return ['private_copy_verified' => true, 'sha256' => $source['sha256'], 'bytes' => (int) $source['size_bytes']];
        }
        $validate($newPath);
        if ($step === 'switch') {
            if ((int) $object['storage_space_id'] === (int) $source['storage_space_id'] && $object['object_key'] === $source['object_key']) {
                $changed = $this->ownedObjects($context->tenantId)->where('file_key', $key)
                    ->where('visibility_batch_key', $batchKey)->where('revision', $object['revision'])
                    ->update(['storage_space_id' => $target['storage_space_id'], 'object_key' => $target['object_key'],
                        'access_type' => 'private', 'status' => 'verifying', 'updated_at' => new Raw('UTC_TIMESTAMP(3)'),
                        'revision' => new Raw('revision+1')]);
                if ($changed !== 1) {
                    throw BusinessException::conflict('FILE_VISIBILITY_OPERATION_CHANGED', '资源位置已改变');
                }
            } elseif ((int) $object['storage_space_id'] !== (int) $target['storage_space_id'] || $object['object_key'] !== $target['object_key']
                || $object['access_type'] !== 'private') {
                throw BusinessException::conflict('FILE_VISIBILITY_OPERATION_CHANGED', '资源位置已改变');
            }
            return ['private_location_selected' => true];
        }
        if ($step === 'close_source') {
            $observation = $sourceDriver->inspect($source['object_key']);
            $receipt = null;
            if ($observation->exists) {
                $validateObservation($observation);
                $materialized = $this->materialize($source + $old);
                try {
                    $validate($materialized['path']);
                    $receipt = $sourceDriver->delete($source['object_key']);
                } finally {
                    if ($materialized['temporary'] && is_file($materialized['path'])) {
                        unlink($materialized['path']);
                    }
                }
            }
            if ($sourceDriver->inspect($source['object_key'])->exists) {
                throw BusinessException::conflict('FILE_VISIBILITY_SOURCE_STILL_PUBLIC', '原公开位置尚未关闭');
            }
            return ['original_location_absent' => true, 'delete_outcome' => $receipt?->outcome ?? 'already-absent'];
        }
        if ($step !== 'verify' || $sourceDriver->inspect($source['object_key'])->exists
            || (int) $object['storage_space_id'] !== (int) $target['storage_space_id']
            || $object['object_key'] !== $target['object_key'] || $object['access_type'] !== 'private') {
            throw BusinessException::conflict('FILE_VISIBILITY_VERIFICATION_FAILED', '原位置或目标位置尚未核实');
        }
        $anonymousStatus = $origin?->verifyAnonymousAbsent($source['object_key']);
        return ['original_location_absent' => true, 'private_bytes_verified' => true,
            'old_delivery_grants' => 'revoked_by_current_status_visibility_and_revision', 'known_cdn_count' => 0,
            'sha256' => $source['sha256'], 'bytes' => (int) $source['size_bytes']]
            + ($anonymousStatus === null ? [] : ['source_anonymous_status' => $anonymousStatus]);
    }

    private function visibilityLocation(array $location): array
    {
        $row = $this->find(StorageSpace::alias('s')->join('storage_account a', 'a.id=s.account_id')
            ->where('s.id', $location['storage_space_id'])->where('s.status', 'active')->where('a.status', 'active')
            ->field('a.*,s.id AS space_id,s.space_key,s.access_type,s.bucket,s.region,s.endpoint,s.access_domain,s.local_path'));
        if ($row === null || $row['driver'] !== $location['driver']
            || $row['space_key'] !== $location['space_key'] || $row['access_type'] !== $location['access_type']) {
            throw BusinessException::conflict('FILE_VISIBILITY_ROUTE_CHANGED', '存储配置已改变，保留现场等待处理');
        }
        if ($row['driver'] === 'local') {
            if ($row['bucket'] !== null || $row['access_domain'] !== null || $row['local_path'] !== $location['local_path']) {
                throw BusinessException::conflict('FILE_VISIBILITY_ROUTE_CHANGED', '本地存储配置已改变');
            }
        } elseif ($row['driver'] === 'qiniu') {
            foreach (['account_key','bucket','region','endpoint','access_domain','credential_key_version'] as $field) {
                if (!array_key_exists($field, $location) || $row[$field] !== $location[$field]) {
                    throw BusinessException::conflict('FILE_VISIBILITY_ROUTE_CHANGED', '云存储配置已改变');
                }
            }
            if ((string) $row['id'] !== ($location['account_id'] ?? null)
                || !hash_equals($location['credential_sha256'] ?? '', hash('sha256', (string) $row['credential_ciphertext']))) {
                throw BusinessException::conflict('FILE_VISIBILITY_ROUTE_CHANGED', '云存储账号已改变');
            }
        } else {
            throw BusinessException::conflict('FILE_VISIBILITY_ORIGIN_UNQUALIFIED', '源站关闭能力尚未确认');
        }
        return $row;
    }

    public static function visibilityJson(mixed $value): string
    {
        $sort = static function (mixed $item) use (&$sort): mixed {
            if (!is_array($item)) {
                return $item;
            }
            if (!array_is_list($item)) {
                ksort($item, SORT_STRING);
            }
            foreach ($item as &$child) {
                $child = $sort($child);
            }
            return $item;
        };
        return json_encode($sort($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function route(string $purpose, string $access): array
    {
        $access = StorageAccess::assertType($access);
        $row = $this->routeRow($purpose, $access) ?? $this->routeRow('default.' . $access, $access);
        if ($row === null) {
            throw new \RuntimeException('文件用途没有可用的存储路由');
        }
        return $row;
    }

    private function objectForTenant(int $tenantId, string $fileKey, bool $readyOnly = true): ?array
    {
        $query = $this->objectQuery($this->logicalTenantId($tenantId))->where('f.file_key', $fileKey);
        if ($readyOnly) {
            $query->where('f.status', 'ready');
        }
        return $this->findOwnedObject($query, false);
    }

    private function deliverableObjectForTenant(int $tenantId, string $fileKey): ?array
    {
        return $this->findOwnedObject(
            $this->objectQuery($this->logicalTenantId($tenantId))
                ->where('f.file_key', $fileKey)
                ->where('f.status', 'ready'),
            true,
        );
    }

    private function publicObject(string $reference): ?array
    {
        $reference = trim($reference);
        $field = 'f.file_key';
        $tenantId = $this->dataScopePolicy->usesTenantColumn() ? null : $this->standaloneTenantId();
        if (preg_match('/^file_[0-9a-f]{32}$/D', $reference) !== 1) {
            $reference = ltrim($reference, '/');
            if (str_starts_with($reference, 'storage/')) {
                $reference = substr($reference, 8);
            }
            if (preg_match('#^tenants/v1/([1-9][0-9]*)/#D', $reference, $matches) !== 1) {
                return null;
            }
            $reference = StorageObjectKey::assert($reference);
            $field = 'f.object_key';
            $referenceTenantId = (int) $matches[1];
            if ($tenantId !== null && $referenceTenantId !== $tenantId) {
                return null;
            }
            $tenantId = $referenceTenantId;
        }
        $object = $this->findOwnedObject(
            $this->objectQuery($tenantId)
                ->where($field, $reference)
                ->where('f.access_type', 'public')
                ->where('f.status', 'ready'),
            true,
        );
        if ($object !== null) {
            $this->authorization->authorizePublic($object);
            $this->assertPublicDerivative($object);
        }
        return $object;
    }

    private function assertPublicDerivative(array $object): void
    {
        $query = Db::name('file_derivative')->where('derivative_file_key', $object['file_key']);
        if ($this->dataScopePolicy->usesTenantColumn()) {
            $query->where('tenant_id', (int) $object['tenant_id']);
        }
        $derivative = $query->find();
        if (is_array($derivative)) {
            $source = $this->deliverableObjectForTenant((int) $object['tenant_id'], (string) $derivative['source_file_key']);
            if ($source === null || $source['access_type'] !== 'public') {
                throw BusinessException::forbidden('FILE_PUBLIC_ACCESS_DENIED', '资源不能公开交付');
            }
            $this->authorization->authorizePublic($source);
        }
    }

    private function reserveObject(int $tenantId, array $data): void
    {
        $tenantId = $this->logicalTenantId($tenantId);
        if (!str_starts_with((string) ($data['object_key'] ?? ''), $this->ownerPrefix($tenantId))) {
            throw new \DomainException('STORAGE_OBJECT_OWNER_MISMATCH');
        }
        FileObject::create([
            ...$data,
            ...($this->dataScopePolicy->usesTenantColumn() ? ['tenant_id' => $tenantId] : []),
            'status' => 'pending_write',
            'revision' => 1,
            'created_at' => new Raw('UTC_TIMESTAMP(3)'),
            'updated_at' => new Raw('UTC_TIMESTAMP(3)'),
            'archived_at' => null,
        ]);
    }

    private function markObjectReady(int $tenantId, string $fileKey): bool
    {
        return $this->changeStatus($tenantId, $fileKey, 'pending_write', 'ready');
    }

    private function markObjectWriteFailed(int $tenantId, string $fileKey): bool
    {
        return $this->changeStatus($tenantId, $fileKey, 'pending_write', 'write_failed');
    }

    private function archive(int $tenantId, string $fileKey): bool
    {
        return $this->ownedObjects($this->logicalTenantId($tenantId))
            ->where('file_key', $fileKey)->where('status', 'ready')
            ->update([
                'status' => 'archived',
                'archived_at' => new Raw('UTC_TIMESTAMP(3)'),
                'updated_at' => new Raw('UTC_TIMESTAMP(3)'),
                'revision' => new Raw('revision+1'),
            ]) === 1;
    }

    private function restore(int $tenantId, string $fileKey): void
    {
        $this->ownedObjects($this->logicalTenantId($tenantId))
            ->where('file_key', $fileKey)->where('status', 'archived')
            ->update([
                'status' => 'ready',
                'archived_at' => null,
                'updated_at' => new Raw('UTC_TIMESTAMP(3)'),
                'revision' => new Raw('revision+1'),
            ]);
    }

    private function routeRow(string $routeKey, string $access): ?array
    {
        return $this->find(
            StorageRoute::alias('r')
                ->join('storage_space s', 's.id=r.space_id')
                ->join('storage_account a', 'a.id=s.account_id')
                ->where('r.route_key', $routeKey)->where('r.access_type', $access)
                ->where('s.access_type', $access)->where('s.status', 'active')->where('a.status', 'active')
                ->field('a.id AS account_id,a.account_key,a.driver,a.name AS account_name,a.credential_ciphertext,a.credential_key_version,a.status AS account_status')
                ->field('s.id AS space_id,s.space_key,s.name AS space_name,s.access_type,s.bucket,s.region,s.endpoint,s.access_domain,s.local_path,s.status AS space_status'),
        );
    }

    private function changeStatus(int $tenantId, string $fileKey, string $from, string $to): bool
    {
        return $this->ownedObjects($this->logicalTenantId($tenantId))
            ->where('file_key', $fileKey)->where('status', $from)
            ->update([
                'status' => $to,
                'updated_at' => new Raw('UTC_TIMESTAMP(3)'),
                'revision' => new Raw('revision+1'),
            ]) === 1;
    }

    private function ownedObjects(int $tenantId): BaseQuery
    {
        return FileObject::where([])
            ->whereLike('object_key', $this->ownerPrefix($tenantId) . '%');
    }

    private function logicalTenantId(int $tenantId): int
    {
        if ($tenantId < 1) {
            throw new \DomainException('STORAGE_TENANT_INVALID');
        }
        if ($this->dataScopePolicy->usesTenantColumn()) {
            return $tenantId;
        }
        $defaultTenantId = $this->standaloneTenantId();
        if ($tenantId !== $defaultTenantId) {
            throw new \DomainException('STORAGE_OBJECT_OWNER_MISMATCH');
        }
        return $defaultTenantId;
    }

    private function standaloneTenantId(): int
    {
        return $this->defaultTenant->system(
            'storage-service',
            'storage.resolve-default-tenant',
            'storage-service-default-tenant',
        )->tenantId;
    }

    private function ownerPrefix(int $tenantId): string
    {
        return 'tenants/v1/' . $tenantId . '/';
    }

    /**
     * 文件模块自己的受控读取边界：私有读取固定租户，公开查询固定 public。
     * 签名交付没有员工会话，不从全局登录上下文推导范围，也不提供通用过滤开关。
     */
    private function objectQuery(?int $tenantId): BaseQuery
    {
        if ($tenantId !== null && $tenantId < 1) {
            throw new \DomainException('STORAGE_TENANT_INVALID');
        }
        $query = Db::name('file_object')->alias('f')
            ->join('storage_space s', 's.id=f.storage_space_id')
            ->join('storage_account a', 'a.id=s.account_id')
            ->field('f.*,a.id AS account_id,a.account_key,a.driver,a.name AS account_name,a.credential_ciphertext,a.credential_key_version,a.status AS account_status')
            ->field('s.space_key,s.name AS space_name,s.bucket,s.region,s.endpoint,s.access_domain,s.local_path,s.status AS space_status');
        if ($tenantId !== null) {
            $query->whereLike('f.object_key', $this->ownerPrefix($tenantId) . '%');
            if ($this->dataScopePolicy->usesTenantColumn()) {
                $query->where('f.tenant_id', $tenantId);
            }
        } else {
            // 无指定租户仅用于公共资源查找，不能返回私有对象。
            $query->where('f.access_type', 'public');
        }
        if (!$this->dataScopePolicy->usesTenantColumn()) {
            if (!is_int($tenantId) || $tenantId < 1) {
                throw new \LogicException('STORAGE_STANDALONE_TENANT_UNAVAILABLE');
            }
            // Identity resolved and validated the default owner before this query.
            $query->fieldRaw($tenantId . ' AS tenant_id');
        }
        return $query;
    }

    private function findOwnedObject(BaseQuery $query, bool $requireActive): ?array
    {
        $row = $this->find($query);
        if ($row === null) {
            return null;
        }
        $status = $this->directory->tenantStatus((int) ($row['tenant_id'] ?? 0));
        if ($status === null || ($requireActive && $status !== 'active')) {
            return null;
        }
        return $row;
    }

    private function find(BaseQuery $query): ?array
    {
        $row = $query->find();
        return $row === null ? null : (is_array($row) ? $row : $row->toArray());
    }

    private function url(array $object, ?array $grant = null, string $operation = 'public', ?array $source = null, ?int $documentId = null): string
    {
        $tenantId = (int) $object['tenant_id'];
        $fileKey = (string) $object['file_key'];
        $private = $object['access_type'] === 'private';
        if ($private && $grant === null) {
            $grant = $this->authorization->authorize($this->execution->tenantAdmin(), $object, 'download_original');
            $operation = 'download_original';
        }
        $source ??= $object;
        $tokenId = bin2hex(random_bytes(16));
        $context = $grant['context'] ?? null;
        $values = [
            'token_id_hash' => hash('sha256', $tokenId), 'subject_tenant_id' => $tenantId,
            'file_key' => $fileKey, 'file_revision' => (int) $object['revision'], 'visibility' => $object['access_type'],
            'source_file_key' => $source['file_key'], 'source_revision' => (int) $source['revision'],
            'source_visibility' => $source['access_type'],
            'operation' => $operation, 'document_id' => $documentId,
            'authorization_revision' => $grant['authorization_revision'] ?? $this->authorization->authorizePublic($source),
            'reference_digest' => $grant['reference_digest'] ?? null,
            'subject_json' => $context instanceof TenantContext ? json_encode([
                'tenant_id' => $context->tenantId, 'account_id' => $context->accountId, 'member_id' => $context->memberId,
                'session_key' => $context->sessionKey, 'client_key' => $context->clientKey,
                'authorization_revision' => $context->authorizationRevision,
            ], JSON_THROW_ON_ERROR) : null,
            'expires_at' => gmdate('Y-m-d H:i:s', time() + ($private || $grant !== null ? 300 : self::DELIVERY_URL_TTL)),
        ];
        Db::name('file_delivery_grant')->insert($values);
        $token = $this->deliveryTokens->issue(
            $tenantId,
            $fileKey,
            $private ? DeliveryVisibility::Private : DeliveryVisibility::Public,
            $private ? ReplayMode::SingleUse : ReplayMode::Bounded,
            new DateTimeImmutable('now', new DateTimeZone('UTC')),
            $private ? 300 : self::DELIVERY_URL_TTL,
            $tokenId,
        );
        return rtrim($this->applicationOrigin, '/') . '/api/storage/delivery?' . http_build_query([
            'tenant_id' => $tenantId,
            'file_key' => $fileKey,
            'token' => $token,
        ]);
    }

    private static function filename(string $value): string
    {
        $value = trim(str_replace(["\0", '/', "\\"], '_', $value));
        if ($value === '') {
            throw new \InvalidArgumentException('文件名无效');
        }
        return mb_substr($value, 0, 255);
    }
}
