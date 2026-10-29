<?php

declare(strict_types=1);

namespace app\platform\identity;

use app\platform\context\PlatformOperatorContext;
use app\platform\services\PlatformOperatorSessionService;

/** Bridges governance services to the independently validated platform session audience. */
final readonly class CorePlatformOperatorIdentityPort implements PlatformOperatorIdentityPort
{
    public function __construct(private PlatformOperatorSessionService $sessions) {}

    public function requireActive(string $credential, string $requestId): PlatformOperatorContext
    {
        return $this->sessions->context($credential, $requestId);
    }
}
