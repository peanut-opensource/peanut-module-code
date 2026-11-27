<?php

declare(strict_types=1);

namespace app\platform\context;

use app\platform\identity\PlatformOperatorIdentity;
use PeanutAdmin\Kernel\Context\PlatformContext;

/** Application-owned proof that Core validated a platform-only session. */
final readonly class PlatformOperatorContext
{
    private function __construct(public PlatformContext $core) {}

    public static function fromValidatedPlatformSession(PlatformContext $context): self
    {
        if ($context->operatorId <= 0
            || $context->accountId <= 0
            || $context->sessionKey === ''
            || $context->clientKey !== 'platform-web'
            || $context->requestId === '') {
            throw new \DomainException('PLATFORM_OPERATOR_CONTEXT_UNTRUSTED');
        }

        return new self($context);
    }

    public function identity(): PlatformOperatorIdentity
    {
        return new PlatformOperatorIdentity($this->core->operatorId, $this->core->accountId);
    }
}
