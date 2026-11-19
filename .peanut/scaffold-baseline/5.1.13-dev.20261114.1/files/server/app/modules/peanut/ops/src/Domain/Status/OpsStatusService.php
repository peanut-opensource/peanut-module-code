<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Ops\Domain\Status;

use PeanutAdmin\Kernel\Context\PlatformContext;
use PeanutAdmin\Modules\Ops\Domain\Application\OpsConsoleException;
use PeanutAdmin\Modules\Ops\Domain\Application\PlatformPermissionChecker;
use PeanutAdmin\Modules\Ops\Domain\Package;
use Throwable;

final readonly class OpsStatusService
{
    /** Fixed permission identity for consumers; knowing it does not grant access. */
    public const READ_PERMISSION = Package::READ_PERMISSION;

    public function __construct(
        private PlatformPermissionChecker $permissions,
        private RuntimeStatusProvider $provider,
    ) {}

    public function read(PlatformContext $context): OpsStatusSnapshot
    {
        if (!$this->permissions->allows($context, self::READ_PERMISSION)) {
            throw OpsConsoleException::denied();
        }
        try {
            return $this->provider->snapshot($context);
        } catch (Throwable) {
            throw OpsConsoleException::statusUnavailable();
        }
    }
}
