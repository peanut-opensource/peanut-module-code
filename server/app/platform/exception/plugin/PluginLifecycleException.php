<?php

declare(strict_types=1);

namespace app\platform\exception\plugin;

final class PluginLifecycleException extends \RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
    ) {
        parent::__construct($message);
    }
}
