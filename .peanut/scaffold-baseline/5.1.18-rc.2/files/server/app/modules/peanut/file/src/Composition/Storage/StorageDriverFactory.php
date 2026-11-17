<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\File\Composition\Storage;

use app\common\contract\storage\StorageCredentialResolver;
use app\common\exception\storage\StorageProviderException;
use app\common\execution\CurrentExecutionContext;
use PeanutAdmin\Modules\File\Infrastructure\Storage\ObservedStorageDriver;
use PeanutAdmin\Modules\File\Infrastructure\Storage\QiniuStorageHttpTransport;
use PeanutAdmin\Modules\File\Infrastructure\Storage\QiniuS3DownloadTransport;
use PeanutAdmin\Modules\File\Infrastructure\Storage\QiniuVisibilityOrigin;
use PeanutAdmin\Modules\File\Infrastructure\Storage\StorageAccess;
use app\common\infrastructure\runtime\OperationalLog;
use PeanutAdmin\FileMedia\Storage\Driver\AliyunStorageDriver;
use PeanutAdmin\FileMedia\Storage\Driver\LocalStorageDriver;
use PeanutAdmin\FileMedia\Storage\Driver\QcloudStorageDriver;
use PeanutAdmin\FileMedia\Storage\Driver\QiniuStorageDriver;
use PeanutAdmin\FileMedia\Storage\StorageDriver;
use Qiniu\Auth;
use think\App;

/** 解析应用存储配置，并以请求时凭据装配 Core 技术 Driver。 */
final class StorageDriverFactory
{
    public function __construct(
        private readonly StorageCredentialResolver $credentials,
        private readonly QiniuStorageHttpTransport $qiniuHttp,
        private readonly AliyunStorageClientFactory $aliyun,
        private readonly QcloudStorageClientFactory $qcloud,
        private readonly CurrentExecutionContext $executionContext,
        private readonly App $app,
    ) {}

    /**
     * 从不可变的 Account/Space 快照创建单次 Driver；可变 Tenant 凭据和 SDK Client 不跨请求缓存。
     */
    public function make(array $account, array $space): StorageDriver
    {
        $provider = (string) ($account['driver'] ?? '');
        try {
            if ($provider !== 'local') {
                $account['resolved_credentials'] = $this->credentials->resolve($account);
            }
            $driver = match ($provider) {
                'local' => $this->local($space),
                'qiniu' => new QiniuStorageDriver(
                    $this->qiniuAuth($account),
                    (string) ($space['bucket'] ?? ''),
                    (string) ($space['endpoint'] ?? ''),
                    (string) ($space['access_domain'] ?? ''),
                    QiniuS3DownloadTransport::usesS3Domain((string) ($space['access_domain'] ?? ''))
                        ? new QiniuS3DownloadTransport($this->qiniuHttp, (string) $space['access_domain'],
                            (string) ($space['bucket'] ?? ''), (string) ($space['region'] ?? ''), $account['resolved_credentials'])
                        : $this->qiniuHttp,
                ),
                'aliyun' => new AliyunStorageDriver(
                    $this->aliyun->make($account, $space),
                    (string) ($space['bucket'] ?? ''),
                ),
                'qcloud' => new QcloudStorageDriver(
                    $this->qcloud->make($account, $space),
                    (string) ($space['bucket'] ?? ''),
                ),
                default => throw new \RuntimeException('存储驱动未注册'),
            };
        } catch (StorageProviderException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            OperationalLog::warning($this->executionContext, 'storage_provider_unconfigured', [
                'provider' => $provider !== '' ? $provider : 'unknown',
                'exception' => $exception::class,
            ]);
            throw StorageProviderException::unconfigured($exception);
        }
        return new ObservedStorageDriver($provider, $driver, $this->executionContext);
    }

    public function qiniuVisibilityOrigin(array $location): QiniuVisibilityOrigin
    {
        if (($location['driver'] ?? null) !== 'qiniu') {
            throw new \InvalidArgumentException('QINIU_ORIGIN_DRIVER_INVALID');
        }
        $location['resolved_credentials'] = $this->credentials->resolve($location);
        return new QiniuVisibilityOrigin($this->qiniuAuth($location), $this->qiniuHttp,
            (string) ($location['bucket'] ?? ''), (string) ($location['region'] ?? ''),
            (string) ($location['access_domain'] ?? ''));
    }

    /** 应用保留产品目录白名单，并只把解析后的绝对根目录与可见性传给 Core。 */
    private function local(array $space): LocalStorageDriver
    {
        $relative = (string) ($space['local_path'] ?? '');
        if (!in_array($relative, ['public/storage', 'private/storage'], true)) {
            throw new \RuntimeException('本地存储空间配置无效');
        }
        $root = rtrim($this->app->getRootPath(), DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);

        return new LocalStorageDriver($root, ($space['access_type'] ?? '') === StorageAccess::PRIVATE);
    }

    /** 使用当前 Account 已解密凭据装配七牛 SDK 身份，不缓存可变 Tenant Client。 */
    private function qiniuAuth(array $account): Auth
    {
        $credentials = (array) ($account['resolved_credentials'] ?? []);

        return new Auth(
            (string) ($credentials['access_key'] ?? ''),
            (string) ($credentials['secret_key'] ?? ''),
        );
    }
}
