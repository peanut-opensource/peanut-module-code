<?php

declare(strict_types=1);

namespace app\platform\exception\module;

final class ModuleLifecycleException extends \RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, previous: $previous);
    }
}
