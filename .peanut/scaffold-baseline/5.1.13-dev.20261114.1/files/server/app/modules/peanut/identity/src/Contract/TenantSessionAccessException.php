<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Identity\Contract;

final class TenantSessionAccessException extends \RuntimeException
{
    private function __construct(public readonly string $problemCode)
    {
        parent::__construct($problemCode);
    }

    public static function denied(): self
    {
        return new self('TENANT_SESSION_ACCESS_DENIED');
    }

    public static function notFound(): self
    {
        return new self('TENANT_SESSION_NOT_FOUND');
    }

    public static function conflict(): self
    {
        return new self('TENANT_SESSION_CONFLICT');
    }
}
