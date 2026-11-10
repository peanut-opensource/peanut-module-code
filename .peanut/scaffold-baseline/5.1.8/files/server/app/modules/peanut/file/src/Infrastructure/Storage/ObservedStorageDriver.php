<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\File\Infrastructure\Storage;

use app\common\exception\storage\StorageProviderException;
use app\common\execution\CurrentExecutionContext;
use app\common\infrastructure\runtime\OperationalLog;
use PeanutAdmin\FileMedia\Storage\StorageDriver;
use PeanutAdmin\FileMedia\Storage\StorageCapabilityUnsupported;
use PeanutAdmin\FileMedia\Storage\StorageDriverCapabilities;
use PeanutAdmin\FileMedia\Storage\StorageObjectObservation;
use PeanutAdmin\FileMedia\Storage\StorageOperationReceipt;
use PeanutAdmin\FileMedia\Storage\StorageVersionCursor;
use PeanutAdmin\FileMedia\Storage\StorageVersionPage;

/** 为 Core 技术 Driver 统一应用层异常语义，并只记录无密钥诊断。 */
final readonly class ObservedStorageDriver implements StorageDriver
{
    public function __construct(
        private string $provider,
        private StorageDriver $delegate,
        private CurrentExecutionContext $executionContext,
    ) {}

    public function capabilities(): StorageDriverCapabilities
    {
        return $this->run('capabilities', fn(): StorageDriverCapabilities => $this->delegate->capabilities());
    }

    public function inspect(string $objectKey, ?string $versionId = null): StorageObjectObservation
    {
        return $this->run('inspect', fn(): StorageObjectObservation => $this->delegate->inspect($objectKey, $versionId));
    }

    public function restrictToPrivate(string $objectKey, ?string $versionId = null): StorageOperationReceipt
    {
        return $this->run('restrict-private', fn(): StorageOperationReceipt => $this->delegate->restrictToPrivate($objectKey, $versionId));
    }

    public function listVersions(string $objectKey, ?StorageVersionCursor $cursor = null, int $limit = 100): StorageVersionPage
    {
        return $this->run('list-versions', fn(): StorageVersionPage => $this->delegate->listVersions($objectKey, $cursor, $limit));
    }

    /** 执行对象写入，并将 Provider 原始异常收敛为应用存储异常。 */
    public function put(string $objectKey, string $sourcePath): void
    {
        $this->run('put', fn() => $this->delegate->put($objectKey, $sourcePath));
    }

    /** 执行对象删除，并保持调用方补偿流程可识别的异常类型。 */
    public function delete(string $objectKey, ?string $versionId = null): StorageOperationReceipt
    {
        return $this->run('delete', fn(): StorageOperationReceipt => $this->delegate->delete($objectKey, $versionId));
    }

    /** 下载对象到调用方拥有的目标路径，不接管临时文件生命周期。 */
    public function downloadTo(string $objectKey, string $targetPath, ?string $versionId = null): void
    {
        $this->run('download', fn() => $this->delegate->downloadTo($objectKey, $targetPath, $versionId));
    }

    /** 返回本地 Driver 的物理路径；云端 Driver 保持 null。 */
    public function localPath(string $objectKey): ?string
    {
        return $this->run('local-path', fn(): ?string => $this->delegate->localPath($objectKey));
    }

    /**
     * 执行单次 Driver 操作；已归一化异常原样上抛，其余故障记录安全诊断后归一化。
     */
    private function run(string $operation, callable $action): mixed
    {
        try {
            return $action();
        } catch (StorageProviderException|StorageCapabilityUnsupported $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            OperationalLog::warning($this->executionContext, 'storage_provider_unavailable', [
                'provider' => $this->provider,
                'operation' => $operation,
                'exception' => $exception::class,
            ]);
            throw StorageProviderException::unavailable($exception);
        }
    }
}
