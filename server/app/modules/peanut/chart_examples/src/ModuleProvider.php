<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\ChartExamples;

use PeanutAdmin\Kernel\Module\ModuleProvider as ModuleProviderContract;

final class ModuleProvider implements ModuleProviderContract
{
    public function moduleKey(): string
    {
        return 'peanut.chart-examples';
    }

    public function bindings(): array
    {
        return [];
    }
}
