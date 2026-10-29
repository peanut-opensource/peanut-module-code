<?php

declare(strict_types=1);

namespace app\common\exception\module;

final class ModuleScaffoldException extends \RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        int $code = 0,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }
}
