<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\ContentTools;

use PeanutAdmin\Kernel\Module\ModuleProvider as ModuleProviderContract;

final class ModuleProvider implements ModuleProviderContract
{
    public function moduleKey(): string
    {
        return 'peanut.content-tools';
    }

    public function bindings(): array
    {
        // ModuleComposition installs the complete registry before any consumer resolves.
        return [];
    }
}
