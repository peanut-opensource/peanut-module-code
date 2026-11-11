<?php

declare(strict_types=1);

namespace app\platform\exception;

final class PlatformRefreshCredentialException extends \RuntimeException
{
    public const ERROR_CODE = 'PLATFORM_REFRESH_CREDENTIAL_INVALID';
    public const MESSAGE = 'Platform refresh credential is invalid.';

    public function __construct(\Throwable $previous)
    {
        parent::__construct(self::MESSAGE, 0, $previous);
    }
}
