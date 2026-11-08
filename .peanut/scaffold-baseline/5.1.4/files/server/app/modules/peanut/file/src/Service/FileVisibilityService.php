<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\File\Service;

use app\common\exception\BusinessException;
use app\common\execution\CurrentExecutionContext;
use app\common\services\audit\AuditContractHost;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Modules\File\Contract\FileVisibility;
use PeanutAdmin\Modules\File\Model\Storage\FileObject;
use PeanutAdmin\Modules\File\Model\Storage\FileVisibilityBatch;
use PeanutAdmin\Modules\File\Model\Storage\FileVisibilityItem;
use PeanutAdmin\Modules\File\Service\Storage\StorageService;
use think\db\Raw;
use think\facade\Db;

/** File business state; ThinkPHP owns persistence and the existing drivers own physical I/O. */
final readonly class FileVisibilityService implements FileVisibility
{
    private const STEPS = ['copy', 'switch', 'close_source', 'verify', 'completed'];

    public function __construct(
        private CurrentExecutionContext $execution,
        private FileAuthorizationService $authorization,
        private StorageService $storage,
        private AuditContractHost $audit,
    ) {}

    public function preview(array $fileKeys): array
    {
        $context = $this->context();
        $batch = 'batch_' . bin2hex(random_bytes(16));
        $manifest = $this->storage->visibilityManifest($context, $fileKeys, $batch);
        foreach ($this->storage->visibilityOriginQualifications($context, $manifest) as $key => $facts) {
            $manifest['files'][$key]['source']['origin_qualification'] = $facts;
        }
        $payload = ['version' => 1, 'batch_key' => $batch, 'actor' => self::actor($context),
            'expires_at' => time() + 300, 'target' => 'private',
            'manifest' => $manifest];
        return ['confirmation' => $payload, 'signature' => $this->storage->visibilityConfirmation($payload),
            'execution_started' => false];
    }

    public function submit(array $confirmation, string $signature, string $requestKey, bool $impactConfirmed): array
    {
        $context = $this->context();
        if (!$impactConfirmed || preg_match('/^[a-zA-Z0-9._:-]{16,96}$/D', $requestKey) !== 1
            || preg_match('/^[a-f0-9]{64}$/D', $signature) !== 1
            || !hash_equals($this->storage->visibilityConfirmation($confirmation), $signature)
            || ($confirmation['version'] ?? null) !== 1 || ($confirmation['target'] ?? null) !== 'private'
            || ($confirmation['actor'] ?? null) !== self::actor($context)
            || !is_int($confirmation['expires_at'] ?? null)
            || !is_array($confirmation['manifest']['roots'] ?? null)
            || !is_string($confirmation['batch_key'] ?? null)) {
            throw BusinessException::invalid('FILE_VISIBILITY_CONFIRMATION_INVALID', '请重新预检查并确认公开引用影响');
        }
        $batch = $confirmation['batch_key'];
        self::batchKey($batch);
        $requestHash = hash('sha256', StorageService::visibilityJson([$confirmation, $signature, true]));
        // Replays observe their durable batch without requiring a now-retired source credential.
        // A new admission reads provider facts before acquiring the short database transaction.
        $qualifications = [];
        if (FileVisibilityBatch::where('request_key', $requestKey)->find() === null) {
            if ($confirmation['expires_at'] <= time()) {
                throw BusinessException::conflict('FILE_VISIBILITY_CONFIRMATION_EXPIRED', '预检查已过期，请重新检查');
            }
            $qualifications = $this->storage->visibilityOriginQualifications($context, $confirmation['manifest']);
            foreach ($qualifications as $key => $facts) {
                if (($confirmation['manifest']['files'][$key]['source']['origin_qualification'] ?? null) !== $facts) {
                    throw BusinessException::conflict('FILE_VISIBILITY_PLAN_CHANGED', '源站或缓存配置已改变，请重新预检查');
                }
            }
        }
        $created = Db::transaction(function () use ($context, $confirmation, $signature, $requestKey, $requestHash, $batch, $qualifications): bool {
            $this->context();
            $existing = FileVisibilityBatch::where('request_key', $requestKey)->lock(true)->find();
            if ($existing !== null) {
                if ($existing['batch_key'] !== $batch || (int) $existing['created_by_member_id'] !== $context->memberId
                    || !hash_equals($existing['request_sha256'], $requestHash)) {
                    throw BusinessException::conflict('FILE_VISIBILITY_IDEMPOTENCY_CONFLICT', '相同请求标识不能改变资源或确认内容');
                }
                return false;
            }
            if ($confirmation['expires_at'] <= time()) {
                throw BusinessException::conflict('FILE_VISIBILITY_CONFIRMATION_EXPIRED', '预检查已过期，请重新检查');
            }
            $manifest = $this->storage->visibilityManifest($context, $confirmation['manifest']['roots'], $batch, true, $qualifications);
            if (StorageService::visibilityJson($manifest) !== StorageService::visibilityJson($confirmation['manifest'])) {
                throw BusinessException::conflict('FILE_VISIBILITY_PLAN_CHANGED', '资源版本、引用或存储配置已改变');
            }
            FileVisibilityBatch::create(['batch_key' => $batch, 'tenant_id' => $context->tenantId,
                'created_by_member_id' => $context->memberId, 'request_key' => $requestKey, 'request_sha256' => $requestHash,
                'confirmation_sha256' => hash('sha256', $signature), 'manifest_json' => StorageService::visibilityJson($manifest),
                'status' => 'pending', 'revision' => 1, 'created_at' => new Raw('UTC_TIMESTAMP(3)'), 'updated_at' => new Raw('UTC_TIMESTAMP(3)')]);
            foreach ($manifest['files'] as $key => $file) {
                $changed = FileObject::where('file_key', $key)->where('status', 'ready')
                    ->whereNull('visibility_batch_key')->where('revision', $file['source']['revision'])
                    ->update(['status' => 'restricting', 'visibility_batch_key' => $batch,
                        'revision' => new Raw('revision+1'), 'updated_at' => new Raw('UTC_TIMESTAMP(3)')]);
                if ($changed !== 1) {
                    throw BusinessException::conflict('FILE_VISIBILITY_PLAN_CHANGED', '资源已被其他操作占用');
                }
                FileVisibilityItem::create(['batch_key' => $batch, 'file_key' => $key, 'tenant_id' => $context->tenantId,
                    'source_json' => StorageService::visibilityJson($file['source']), 'target_json' => StorageService::visibilityJson($file['target']),
                    'receipts_json' => '{}', 'status' => 'restricting', 'step' => 'copy', 'attempts' => 0, 'error_code' => null,
                    'created_at' => new Raw('UTC_TIMESTAMP(3)'), 'updated_at' => new Raw('UTC_TIMESTAMP(3)')]);
            }
            $this->audit->appendTenantMember(
                $context,
                'file.visibility.batch.accepted',
                'file.visibility.submit',
                'file.visibility.batch',
                $batch,
                null,
                null,
                count($manifest['files']),
                hash('sha256', StorageService::visibilityJson($manifest)),
                ['target' => 'private', 'registered_reference_count' => $manifest['registered_reference_count'], 'external_usage_complete' => false],
            );
            return true;
        });
        return $created ? $this->advance($batch) : $this->status($batch);
    }

    public function status(string $batchKey): array
    {
        $this->context();
        self::batchKey($batchKey);
        $batch = FileVisibilityBatch::where('batch_key', $batchKey)->find();
        if ($batch === null) {
            throw BusinessException::notFound('FILE_VISIBILITY_BATCH_NOT_FOUND', '批次不存在');
        }
        $items = FileVisibilityItem::where('batch_key', $batchKey)->order('file_key')->select()->toArray();
        return ['batch_key' => $batchKey, 'status' => $batch['status'], 'revision' => (int) $batch['revision'],
            'created_at' => $batch['created_at'], 'updated_at' => $batch['updated_at'],
            'items' => array_map(static fn(array $item): array => ['file_key' => $item['file_key'], 'status' => $item['status'],
                'step' => $item['step'], 'attempts' => (int) $item['attempts'], 'error_code' => $item['error_code'],
                'receipts' => $item['receipts_json']], $items),
            'external_usage_complete' => false, 'downloaded_copies_recoverable' => false];
    }

    public function resume(string $batchKey): array
    {
        $context = $this->context();
        self::batchKey($batchKey);
        Db::transaction(function () use ($context, $batchKey): void {
            $batch = FileVisibilityBatch::where('batch_key', $batchKey)->lock(true)->find();
            if ($batch === null) {
                throw BusinessException::notFound('FILE_VISIBILITY_BATCH_NOT_FOUND', '批次不存在');
            }
            $this->audit->appendTenantMember($context, 'file.visibility.batch.resume', 'file.visibility.resume', 'file.visibility.batch', $batchKey);
        });
        return $this->advance($batchKey);
    }

    public function recent(): array
    {
        $this->context();
        return FileVisibilityBatch::where([])->order(['created_at' => 'desc', 'batch_key' => 'desc'])->limit(50)
            ->field('batch_key,status,revision,created_at,updated_at')->select()->toArray();
    }

    private function advance(string $batchKey): array
    {
        $context = $this->context();
        self::batchKey($batchKey);
        $connection = Db::connect();
        $connection->query('SELECT CONNECTION_ID()', [], true);
        $pdo = $connection->getPdo();
        if (!$pdo instanceof \PDO || $pdo->inTransaction()) {
            throw BusinessException::conflict('FILE_VISIBILITY_TRANSACTION_ACTIVE', '当前操作仍在事务中');
        }
        $lock = \app\common\value\runtime\RuntimeNamespace::fromConfiguration()->advisoryLockName(
            'file-visibility:' . substr(hash('sha256', $context->tenantId . ':' . $batchKey), 0, 32),
        );
        $q = $pdo->prepare('SELECT GET_LOCK(?,0)');
        $q->execute([$lock]);
        $acquired = (int) $q->fetchColumn() === 1;
        $q->closeCursor();
        if (!$acquired) {
            return $this->status($batchKey);
        }
        try {
            $batch = FileVisibilityBatch::where('batch_key', $batchKey)->find();
            if ($batch === null) {
                throw BusinessException::notFound('FILE_VISIBILITY_BATCH_NOT_FOUND', '批次不存在');
            }
            if ($batch['status'] === 'completed') {
                return $this->status($batchKey);
            }
            FileVisibilityBatch::where('batch_key', $batchKey)->update(['status' => 'running', 'revision' => new Raw('revision+1'), 'updated_at' => new Raw('UTC_TIMESTAMP(3)')]);
            $keys = FileVisibilityItem::where('batch_key', $batchKey)->order('file_key')->column('file_key');
            foreach ($keys as $fileKey) {
                while (true) {
                    $item = FileVisibilityItem::where('batch_key', $batchKey)->where('file_key', $fileKey)->find()?->toArray();
                    if ($item === null) {
                        throw BusinessException::conflict('FILE_VISIBILITY_ITEM_MISSING', '批次记录不完整');
                    }
                    if ($item['status'] === 'ready_private') {
                        break;
                    }
                    $step = $item['step'];
                    $position = array_search($step, self::STEPS, true);
                    if ($position === false || $step === 'completed') {
                        throw BusinessException::conflict('FILE_VISIBILITY_STEP_INVALID', '批次阶段无效');
                    }
                    try {
                        // Commit the exact next intent before any filesystem operation. A later
                        // resume inspects these same locations; it never creates another batch.
                        Db::transaction(function () use ($context, $batchKey, $fileKey, $step): void {
                            $this->context();
                            $this->item($batchKey, $fileKey)->lock(true)->findOrFail();
                            $this->item($batchKey, $fileKey)->update(['attempts' => new Raw('attempts+1'), 'error_code' => null,
                                'updated_at' => new Raw('UTC_TIMESTAMP(3)')]);
                            $this->audit->appendTenantMember(
                                $context,
                                'file.visibility.item.intent',
                                'file.visibility.' . $step,
                                'file',
                                $fileKey,
                                'file.visibility.batch',
                                $batchKey,
                            );
                        });
                        $receipt = $this->storage->visibilityStorageStep(
                            $context,
                            $batchKey,
                            $item['source_json'],
                            $item['target_json'],
                            $step,
                        );
                        Db::transaction(function () use ($context, $batchKey, $fileKey, $step, $position, $receipt): void {
                            $this->context();
                            $current = $this->item($batchKey, $fileKey)->lock(true)->findOrFail()->toArray();
                            if ($current['step'] !== $step) {
                                throw BusinessException::conflict('FILE_VISIBILITY_STEP_CHANGED', '批次阶段已改变');
                            }
                            $receipts = $current['receipts_json'];
                            $receipts[$step] = $receipt;
                            if ($step === 'verify') {
                                $changed = FileObject::where('file_key', $fileKey)->where('visibility_batch_key', $batchKey)
                                    ->where('access_type', 'private')->whereIn('status', ['verifying','restricted_attention'])
                                    ->update(['status' => 'ready', 'visibility_batch_key' => null, 'revision' => new Raw('revision+1'),
                                        'updated_at' => new Raw('UTC_TIMESTAMP(3)')]);
                                if ($changed !== 1) {
                                    throw BusinessException::conflict('FILE_VISIBILITY_OPERATION_CHANGED', '资源操作已改变');
                                }
                            }
                            $this->item($batchKey, $fileKey)->update(['status' => $step === 'verify' ? 'ready_private' : ($step === 'copy' ? 'restricting' : 'verifying'),
                                'step' => self::STEPS[$position + 1], 'receipts_json' => StorageService::visibilityJson($receipts),
                                'error_code' => null, 'updated_at' => new Raw('UTC_TIMESTAMP(3)')]);
                            $this->audit->appendTenantMember(
                                $context,
                                'file.visibility.item.accepted',
                                'file.visibility.' . $step,
                                'file',
                                $fileKey,
                                'file.visibility.batch',
                                $batchKey,
                                1,
                                hash('sha256', StorageService::visibilityJson($receipt)),
                            );
                        });
                    } catch (\Throwable $failure) {
                        $code = $failure instanceof BusinessException ? $failure->errorCode : 'FILE_VISIBILITY_STORAGE_FAILED';
                        Db::transaction(function () use ($context, $batchKey, $fileKey, $code): void {
                            FileObject::where('file_key', $fileKey)->where('visibility_batch_key', $batchKey)
                                ->whereIn('status', ['restricting','verifying','restricted_attention'])
                                ->update(['status' => 'restricted_attention', 'revision' => new Raw('revision+1'), 'updated_at' => new Raw('UTC_TIMESTAMP(3)')]);
                            $this->item($batchKey, $fileKey)->update(['status' => 'restricted_attention', 'error_code' => $code, 'updated_at' => new Raw('UTC_TIMESTAMP(3)')]);
                            $this->audit->appendTenantMember(
                                $context,
                                'file.visibility.item.attention',
                                'file.visibility.restrict',
                                'file',
                                $fileKey,
                                'file.visibility.batch',
                                $batchKey,
                                0,
                                null,
                                ['error_code' => $code],
                            );
                        });
                        break;
                    }
                }
            }
            Db::transaction(function () use ($context, $batchKey): void {
                $remaining = FileVisibilityItem::where('batch_key', $batchKey)->where('status', '<>', 'ready_private')->count();
                FileVisibilityBatch::where('batch_key', $batchKey)->update(['status' => $remaining === 0 ? 'completed' : 'attention',
                    'revision' => new Raw('revision+1'), 'updated_at' => new Raw('UTC_TIMESTAMP(3)')]);
                $this->audit->appendTenantMember(
                    $context,
                    'file.visibility.batch.observed',
                    'file.visibility.status',
                    'file.visibility.batch',
                    $batchKey,
                    null,
                    null,
                    0,
                    null,
                    ['status' => $remaining === 0 ? 'completed' : 'attention'],
                );
            });
            return $this->status($batchKey);
        } finally {
            $q = $pdo->prepare('SELECT RELEASE_LOCK(?)');
            $q->execute([$lock]);
            $q->closeCursor();
        }
    }

    private function item(string $batch, string $file): \think\db\BaseQuery
    {
        return FileVisibilityItem::where('batch_key', $batch)->where('file_key', $file);
    }

    private function context(): TenantContext
    {
        $context = $this->execution->tenantAdmin();
        $this->authorization->requireManagementPermission($context, 'peanut.file.visibility.change');
        return $context;
    }

    private static function actor(TenantContext $context): array
    {
        return ['tenant_id' => (string) $context->tenantId, 'account_id' => (string) $context->accountId, 'member_id' => (string) $context->memberId,
            'client_key' => $context->clientKey, 'session_digest' => hash('sha256', $context->sessionKey),
            'authorization_revision' => (string) $context->authorizationRevision];
    }

    private static function batchKey(string $key): void
    {
        if (preg_match('/^batch_[a-f0-9]{32}$/D', $key) !== 1) {
            throw BusinessException::invalid('FILE_VISIBILITY_BATCH_INVALID', '批次标识无效');
        }
    }
}
