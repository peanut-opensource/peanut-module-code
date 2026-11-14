<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Settings\Definition;

use app\platform\infrastructure\module\DeployedTenantModuleRegistry;

final readonly class DeployedSettingDefinitionRegistry
{
    public function __construct(private DeployedTenantModuleRegistry $modules) {}

    public function build(): SettingDefinitionRegistry
    {
        $registry = new SettingDefinitionRegistry();
        $loader = new SettingDefinitionLoader();
        foreach ($this->modules->compiled()->modules as $manifest) {
            $key = (string) ($manifest->data['key'] ?? '');
            $backend = is_array($manifest->data['backend'] ?? null) ? $manifest->data['backend'] : [];
            $resource = $backend['setting_definitions'] ?? null;
            $registry->registerModule($key, is_string($resource)
                ? $loader->load($key, $manifest->root . '/' . ltrim($resource, '/'))
                : []);
        }
        return $registry;
    }
}
