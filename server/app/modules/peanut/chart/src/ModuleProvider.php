<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Chart;

use PeanutAdmin\Kernel\Module\ModuleProvider as ModuleProviderContract;

final class ModuleProvider implements ModuleProviderContract
{
    public function moduleKey(): string
    {
        return 'peanut.chart';
    }

    public function bindings(): array
    {
        return [];
    }
}
