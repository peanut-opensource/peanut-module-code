<?php

declare(strict_types=1);

namespace app\common\exception\storage;

final class StorageProviderException extends \RuntimeException
{
    private function __construct(
        public readonly string $errorCode,
        public readonly int $httpStatus,
        string $message,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function unavailable(?\Throwable $previous = null): self
    {
        return new self('STORAGE_PROVIDER_UNAVAILABLE', 503, '存储服务当前不可用', $previous);
    }

    public static function unconfigured(?\Throwable $previous = null): self
    {
        return new self('STORAGE_PROVIDER_UNCONFIGURED', 409, '存储服务尚未正确配置', $previous);
    }
}
