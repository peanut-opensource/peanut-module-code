<?php

declare(strict_types=1);

namespace app\platform\exception\module;

final class ModulePackageException extends \RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        int $code = 0,
        ?\Throwable $previous = null,
        public readonly array $details = [],
    ) {
        parent::__construct($message, $code, $previous);
    }
}
