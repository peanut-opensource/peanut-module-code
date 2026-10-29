<?php

declare(strict_types=1);

namespace app\platform\identity;

use app\platform\context\PlatformOperatorContext;

final class UnavailablePlatformOperatorIdentityPort implements PlatformOperatorIdentityPort
{
    public function requireActive(string $credential, string $requestId): PlatformOperatorContext
    {
        throw new \DomainException('PLATFORM_OPERATOR_AUTHENTICATION_UNAVAILABLE');
    }
}
