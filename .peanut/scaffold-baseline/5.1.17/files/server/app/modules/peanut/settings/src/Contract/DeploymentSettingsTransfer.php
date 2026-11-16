<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Settings\Contract;

use PeanutAdmin\Kernel\Context\PlatformContext;

interface DeploymentSettingsTransfer
{
    /** @return list<array{key:string,exists:bool,secret:bool,configured:bool,value:mixed,revision:?int}> */
    public function snapshot(PlatformContext $context): array;

    /** @return array{key:string,exists:bool,secret:bool,configured:bool,value:mixed,revision:?int} */
    public function current(PlatformContext $context, string $key): array;

    public function apply(PlatformContext $context, string $key, mixed $value, bool $unset, ?int $revision): void;
}
