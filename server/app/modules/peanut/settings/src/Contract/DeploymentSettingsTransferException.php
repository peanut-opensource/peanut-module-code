<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Settings\Contract;

final class DeploymentSettingsTransferException extends \RuntimeException
{
    public function __construct(public readonly string $errorCode, ?\Throwable $previous = null)
    {
        parent::__construct($errorCode, 0, $previous);
    }
}
