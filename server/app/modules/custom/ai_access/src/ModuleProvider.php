<?php
declare(strict_types=1);

namespace Custom\Modules\AiAccess;

use Custom\Modules\AiAccess\Contract\AiClient;
use Custom\Modules\AiAccess\Service\ProviderClient;
use PeanutAdmin\Kernel\Module\ModuleProvider as ModuleProviderContract;

final class ModuleProvider implements ModuleProviderContract
{
    public function moduleKey(): string
    {
        return 'custom.ai-access';
    }

    public function bindings(): array
    {
        return [AiClient::class => ProviderClient::class];
    }
}
