<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Identity\access\SourceRead;

use PeanutAdmin\DataPermission\Exception\DataAuthorizationException;

final readonly class SourceReadCapabilityRegistry
{
    /** @var array<string, SourceReadCapability> */
    private array $capabilities;

    /** @param list<SourceReadCapability> $capabilities */
    public function __construct(array $capabilities)
    {
        $indexed = [];
        foreach ($capabilities as $capability) {
            if (!$capability instanceof SourceReadCapability) {
                throw new \InvalidArgumentException('SOURCE_READ_CAPABILITY_INVALID');
            }
            $identity = $capability->key . ':' . $capability->action;
            if (isset($indexed[$identity])) {
                throw new \InvalidArgumentException('SOURCE_READ_CAPABILITY_DUPLICATE');
            }
            $indexed[$identity] = $capability;
        }
        $this->capabilities = $indexed;
    }

    public function require(string $capability, string $action): SourceReadCapability
    {
        return $this->capabilities[$capability . ':' . $action]
            ?? throw new DataAuthorizationException(
                'AUTHZ_READ_CAPABILITY_UNKNOWN',
                'The source-read capability is not registered by the server.',
            );
    }
}
